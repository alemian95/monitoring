<?php

namespace App\Support;

use App\Enums\MonitorType;
use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PDO;
use PDOException;

class MonitorProbe
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * @throws MonitorCheckFailed quando il target non risponde come atteso.
     */
    public function check(Monitor $monitor): ProbeResult
    {
        if (! $monitor->is_inverted) {
            return $this->probe($monitor);
        }

        // Upside-down: un target che deve restare irraggiungibile — una porta
        // che non va esposta, una pagina di manutenzione che deve sparire. Il
        // fallimento diventa il caso buono, e viceversa.
        try {
            $result = $this->probe($monitor);
        } catch (MonitorCheckFailed|ConnectionException) {
            return new ProbeResult(null);
        }

        throw new MonitorCheckFailed(
            'Il target risponde, ma il monitor è invertito: atteso che non risponda',
            $result->statusCode,
            $result->responseTimeMs,
        );
    }

    /**
     * @throws MonitorCheckFailed
     */
    private function probe(Monitor $monitor): ProbeResult
    {
        return match ($monitor->type) {
            MonitorType::Http => $this->checkHttp($monitor),
            MonitorType::Tcp => $this->checkTcp($monitor),
            MonitorType::Dns => $this->checkDns($monitor),
            MonitorType::Database => $this->checkDatabase($monitor),
            MonitorType::Push => $this->checkPush($monitor),
        };
    }

    private function checkHttp(Monitor $monitor): ProbeResult
    {
        $startedAt = hrtime(true);

        $response = Http::timeout($monitor->timeout_seconds)
            ->withHeaders($monitor->http_headers ?? [])
            ->send(
                $monitor->http_method ?: 'GET',
                (string) $monitor->target,
                filled($monitor->http_body) ? ['body' => $monitor->http_body] : [],
            );

        $elapsedMs = $this->elapsedMs($startedAt);

        $status = $response->status();

        // ponytail: una lista vuota vale [200]. Il campo e' nullable perche' i
        // monitor TCP non lo compilano, e un monitor HTTP senza codici attesi
        // vuole dire il default, non "qualunque risposta va bene".
        //
        // `intval` perche' il TagsInput del form salva stringhe: senza
        // normalizzare, il confronto stretto con lo status intero fallirebbe
        // sempre, e ogni target risulterebbe giu'.
        $expected = array_map(intval(...), $monitor->expected_statuses ?: [200]);

        if (! in_array($status, $expected, true)) {
            throw new MonitorCheckFailed(
                "HTTP {$status}, attesi ".implode(', ', $expected),
                $status,
                $elapsedMs,
            );
        }

        $this->assertContainsExpected($monitor, $response->body(), 'Corpo della risposta', $status, $elapsedMs);

        $this->assertJsonValue($monitor, $response, $status, $elapsedMs);

        $this->assertWithinThreshold($monitor, $elapsedMs, $status);

        return new ProbeResult($elapsedMs, $status);
    }

    /**
     * Il database accetta connessioni e risponde a una query.
     *
     * Una connessione costruita al volo e buttata subito dopo: tenerla aperta
     * fra un check e l'altro direbbe che il database era vivo quando l'abbiamo
     * aperta, non adesso.
     *
     * ponytail: solo `select 1`. Upgrade path, se servira' sapere qualcosa di
     * piu' (una replica in ritardo, una coda che cresce): una colonna con la
     * query e il valore atteso.
     *
     * @throws MonitorCheckFailed
     */
    private function checkDatabase(Monitor $monitor): ProbeResult
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration(['url' => (string) $monitor->connection_url]);
        $config['name'] = "monitor-{$monitor->id}";
        $config['options'] = [PDO::ATTR_TIMEOUT => $monitor->timeout_seconds];

        $startedAt = hrtime(true);

        try {
            DB::build($config)->select('select 1');
        } catch (QueryException|PDOException|InvalidArgumentException $exception) {
            // Il messaggio del driver e non quello di Laravel, che aggiunge il
            // nome interno della connessione e la query: rumore, nell'alert.
            throw new MonitorCheckFailed(
                'Database — '.($exception->getPrevious()?->getMessage() ?? $exception->getMessage()),
                responseTimeMs: $this->elapsedMs($startedAt),
            );
        } finally {
            DB::purge($config['name']);
        }

        $elapsedMs = $this->elapsedMs($startedAt);

        $this->assertWithinThreshold($monitor, $elapsedMs, null);

        return new ProbeResult($elapsedMs);
    }

    private function checkTcp(Monitor $monitor): ProbeResult
    {
        // ponytail: TCP connect, non ICMP ping. Il ping vero richiede exec() e
        // privilegi, è filtrato su molti hosting e va parsato a mano; inoltre un
        // kernel vivo con il servizio morto supera il ping. Upgrade path: un caso
        // MonitorType::Icmp con un ramo dedicato, dove i privilegi lo permettono.
        $startedAt = hrtime(true);

        $connection = @fsockopen(
            $monitor->target,
            $monitor->port,
            $errno,
            $errstr,
            $monitor->timeout_seconds,
        );

        $elapsedMs = $this->elapsedMs($startedAt);

        if ($connection === false) {
            throw new MonitorCheckFailed(
                "TCP {$monitor->target}:{$monitor->port} — {$errstr} ({$errno})",
                responseTimeMs: $elapsedMs,
            );
        }

        fclose($connection);

        $this->assertWithinThreshold($monitor, $elapsedMs, null);

        return new ProbeResult($elapsedMs);
    }

    /**
     * Un record che sparisce o che viene ripuntato altrove non lo vede nessun
     * check HTTP: il nuovo indirizzo risponde 200 come il vecchio.
     *
     * ponytail: il resolver di sistema non accetta un timeout, quindi
     * `timeout_seconds` qui non vale. Upgrade path se servira' davvero: una
     * query DNS a mano su socket, che e' un ordine di grandezza piu' codice.
     *
     * @throws MonitorCheckFailed
     */
    private function checkDns(Monitor $monitor): ProbeResult
    {
        $type = $monitor->dns_record_type;
        $startedAt = hrtime(true);

        $records = $this->dns->records((string) $monitor->target, $type);

        $elapsedMs = $this->elapsedMs($startedAt);

        if (empty($records)) {
            throw new MonitorCheckFailed(
                "Nessun record {$type->value} per {$monitor->target}",
                responseTimeMs: $elapsedMs,
            );
        }

        $answer = $this->answerOf($records);

        $this->assertContainsExpected($monitor, $answer, "Record {$type->value} «{$answer}»", null, $elapsedMs);

        $this->assertWithinThreshold($monitor, $elapsedMs, null);

        return new ProbeResult($elapsedMs);
    }

    /**
     * Il check invertito: non contattiamo niente, guardiamo da quanto il job
     * non ci chiama. La freschezza la decide `grace_minutes`, che e' il periodo
     * atteso fra due ping e non ha niente a che vedere con `interval_minutes`,
     * cioe' ogni quanto guardiamo noi.
     *
     * ponytail: nessun tempo di risposta da riportare — `null` e non zero, o la
     * media dei tempi di un push monitor sarebbe sempre zero.
     *
     * @throws MonitorCheckFailed
     */
    private function checkPush(Monitor $monitor): ProbeResult
    {
        if (filled($monitor->last_ping_failure)) {
            throw new MonitorCheckFailed($monitor->last_ping_failure);
        }

        if ($monitor->last_ping_at === null) {
            throw new MonitorCheckFailed('Nessun ping ricevuto');
        }

        if ($monitor->last_ping_at->lessThan(now()->subMinutes($monitor->grace_minutes))) {
            throw new MonitorCheckFailed(
                "Ultimo ping {$monitor->last_ping_at->diffForHumans()}, atteso ogni {$monitor->grace_minutes} min"
            );
        }

        return new ProbeResult(null);
    }

    /**
     * Le risposte DNS, appiattite in una riga leggibile.
     *
     * Le chiavi di servizio restano fuori: cercare «A» in un record non deve
     * trovare il campo `type`.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function answerOf(array $records): string
    {
        return collect($records)
            ->map(fn (array $record): string => implode(' ', array_filter(
                Arr::except($record, ['host', 'class', 'ttl', 'type']),
                is_scalar(...),
            )))
            ->filter()
            ->implode(', ');
    }

    /**
     * Il testo atteso, se configurato, deve comparire nella risposta.
     *
     * Vale per il corpo HTTP e per la risposta DNS: e' la stessa domanda — «la
     * risposta contiene ancora quello che mi aspetto» — e per questo e' la
     * stessa colonna. Con `invert_keyword` la domanda si capovolge: il testo
     * non deve esserci, come «Errore» o «Manutenzione» in una pagina che
     * risponde comunque 200.
     *
     * @throws MonitorCheckFailed
     */
    private function assertContainsExpected(
        Monitor $monitor,
        string $haystack,
        string $subject,
        ?int $statusCode,
        int $elapsedMs,
    ): void {
        $expected = $monitor->expected_body_contains;

        if (blank($expected) || str_contains($haystack, $expected) !== $monitor->invert_keyword) {
            return;
        }

        throw new MonitorCheckFailed(
            $monitor->invert_keyword ? "{$subject} con «{$expected}»" : "{$subject} senza «{$expected}»",
            $statusCode,
            $elapsedMs,
        );
    }

    /**
     * Un valore dentro una risposta JSON, letto con la dot notation di
     * `data_get` (`data.status`, `checks.0.healthy`).
     *
     * Senza valore atteso basta che il campo esista. Il confronto e' fra
     * stringhe, perche' il valore atteso arriva da un campo di testo: `true`
     * e `1` si scrivono come li stampa JSON.
     *
     * ponytail: solo uguaglianza. Upgrade path se servira' una soglia (`< 100`
     * elementi in coda): un operatore accanto al valore atteso.
     *
     * @throws MonitorCheckFailed
     */
    private function assertJsonValue(Monitor $monitor, Response $response, int $statusCode, int $elapsedMs): void
    {
        if (blank($monitor->json_path)) {
            return;
        }

        $value = data_get($response->json(), $monitor->json_path);

        if ($value === null) {
            throw new MonitorCheckFailed("JSON senza «{$monitor->json_path}»", $statusCode, $elapsedMs);
        }

        if (blank($monitor->json_expected_value)) {
            return;
        }

        $actual = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($actual !== $monitor->json_expected_value) {
            throw new MonitorCheckFailed(
                "JSON «{$monitor->json_path}» vale «{$actual}», atteso «{$monitor->json_expected_value}»",
                $statusCode,
                $elapsedMs,
            );
        }
    }

    /**
     * Un target troppo lento e' un target giu'. Con i quattro tentativi del job
     * un singolo picco non fa scattare nulla: serve lentezza continuata per
     * circa un minuto. Soglia nulla significa "registra il tempo e basta".
     *
     * @throws MonitorCheckFailed
     */
    private function assertWithinThreshold(Monitor $monitor, int $elapsedMs, ?int $statusCode): void
    {
        if ($monitor->max_response_time_ms === null || $elapsedMs <= $monitor->max_response_time_ms) {
            return;
        }

        throw new MonitorCheckFailed(
            "Risposta in {$elapsedMs} ms, oltre il limite di {$monitor->max_response_time_ms} ms",
            $statusCode,
            $elapsedMs,
        );
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
