<?php

namespace App\Support;

use App\Enums\MonitorType;
use App\Enums\TlsMode;
use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

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
            MonitorType::Redis => $this->checkRedis($monitor),
            MonitorType::Smtp => $this->checkSmtp($monitor),
            MonitorType::Imap => $this->checkMailbox($monitor, 'IMAP', '* OK', 'a1 STARTTLS', 'a1 OK', 'a2 LOGOUT'),
            MonitorType::Pop3 => $this->checkMailbox($monitor, 'POP3', '+OK', 'STLS', '+OK', 'QUIT'),
            MonitorType::Icmp => $this->checkIcmp($monitor),
            MonitorType::Group => $this->checkGroup($monitor),
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
     * Redis risponde `+PONG` a un `PING`. La porta aperta non basta: un Redis
     * che ha finito la memoria o che e' in caricamento accetta connessioni e
     * rifiuta i comandi.
     *
     * L'URL e' quello di sempre — `redis://:password@host:6379`, `rediss://`
     * per TLS — e sta in `connection_url`, cifrato, per via della password.
     *
     * @throws MonitorCheckFailed
     */
    private function checkRedis(Monitor $monitor): ProbeResult
    {
        $url = parse_url((string) $monitor->connection_url) ?: [];
        $scheme = ($url['scheme'] ?? null) === 'rediss' ? 'tls' : 'tcp';
        $address = "{$scheme}://".($url['host'] ?? '').':'.($url['port'] ?? 6379);

        $startedAt = hrtime(true);
        $socket = $this->openSocket('Redis', $address, $monitor, $startedAt);

        try {
            if (filled($url['pass'] ?? null)) {
                $credentials = filled($url['user'] ?? null)
                    ? [urldecode($url['user']), urldecode($url['pass'])]
                    : [urldecode($url['pass'])];

                $this->redisCommand($socket, ['AUTH', ...$credentials], '+OK', $startedAt);
            }

            $this->redisCommand($socket, ['PING'], '+PONG', $startedAt);
        } finally {
            fclose($socket);
        }

        $elapsedMs = $this->elapsedMs($startedAt);

        $this->assertWithinThreshold($monitor, $elapsedMs, null);

        return new ProbeResult($elapsedMs);
    }

    /**
     * Il server SMTP saluta con 220, accetta `EHLO` e, se richiesto, passa a
     * TLS. Il TCP connect si ferma prima: un Postfix con la coda piena apre la
     * porta e risponde 421.
     *
     * Il certificato viene verificato, sia in TLS implicito sia dopo
     * `STARTTLS` (vedi `startTls()`).
     *
     * ponytail: nessun `AUTH` e nessun invio. Upgrade path se servira'
     * sapere che la posta parte davvero: un push monitor chiamato da chi la
     * riceve, che e' l'unico a poterlo dire.
     *
     * @throws MonitorCheckFailed
     */
    private function checkSmtp(Monitor $monitor): ProbeResult
    {
        $startedAt = hrtime(true);
        $socket = $this->openMailSocket($monitor, 'SMTP', $startedAt);

        try {
            $this->smtpCommand($socket, null, 220, $startedAt);
            $this->smtpCommand($socket, "EHLO {$this->heloName()}", 250, $startedAt);

            if ($monitor->tls_mode === TlsMode::Starttls) {
                $this->smtpCommand($socket, 'STARTTLS', 220, $startedAt);
                $this->startTls($socket, 'SMTP STARTTLS', $startedAt);
                $this->smtpCommand($socket, "EHLO {$this->heloName()}", 250, $startedAt);
            }

            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }

        $elapsedMs = $this->elapsedMs($startedAt);

        $this->assertWithinThreshold($monitor, $elapsedMs, null);

        return new ProbeResult($elapsedMs);
    }

    /**
     * IMAP e POP3: il server saluta, passa a TLS se richiesto, e ci lascia
     * andare. Cambiano solo le parole, che arrivano da chi chiama.
     *
     * ponytail: nessun login. Una casella che accetta la connessione ma
     * rifiuta le credenziali resta su; upgrade path se servira': utente e
     * password cifrati e un `LOGIN`/`USER`+`PASS` prima dell'uscita.
     *
     * @throws MonitorCheckFailed
     */
    private function checkMailbox(
        Monitor $monitor,
        string $protocol,
        string $greeting,
        string $startTlsCommand,
        string $startTlsReply,
        string $quitCommand,
    ): ProbeResult {
        $startedAt = hrtime(true);
        $socket = $this->openMailSocket($monitor, $protocol, $startedAt);

        try {
            $this->mailboxCommand($socket, $protocol, null, $greeting, $startedAt);

            if ($monitor->tls_mode === TlsMode::Starttls) {
                $this->mailboxCommand($socket, $protocol, $startTlsCommand, $startTlsReply, $startedAt);
                $this->startTls($socket, "{$protocol} ".Str::afterLast($startTlsCommand, ' '), $startedAt);
            }

            fwrite($socket, "{$quitCommand}\r\n");
        } finally {
            fclose($socket);
        }

        $elapsedMs = $this->elapsedMs($startedAt);

        $this->assertWithinThreshold($monitor, $elapsedMs, null);

        return new ProbeResult($elapsedMs);
    }

    /**
     * Un ping ICMP con il `ping` di sistema: l'unico che non chiede privilegi
     * a PHP, perche' il socket raw lo apre lui.
     *
     * Dice che la macchina e' accesa e raggiungibile, non che il servizio
     * gira: un kernel vivo con nginx morto risponde. Per i servizi c'e' il
     * check del loro protocollo.
     *
     * ponytail: un solo pacchetto, IPv4. Un pacchetto perso non basta a
     * dichiarare giu' — ci pensano i quattro tentativi del job. Upgrade path:
     * `-c` configurabile, e `ping6` dove serve.
     *
     * @throws MonitorCheckFailed
     */
    private function checkIcmp(Monitor $monitor): ProbeResult
    {
        $host = (string) $monitor->target;

        // Un argomento che comincia con «-» e' un'opzione di ping, non un host.
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9.:-]*$/', $host)) {
            throw new MonitorCheckFailed("ICMP — host non valido «{$host}»");
        }

        // Stessa opzione, unita' diverse: millisecondi su macOS, secondi su Linux.
        $wait = PHP_OS_FAMILY === 'Darwin' ? $monitor->timeout_seconds * 1000 : $monitor->timeout_seconds;

        $result = Process::timeout($monitor->timeout_seconds + 5)
            ->run(['ping', '-c', '1', '-W', (string) $wait, $host]);

        // 126/127: `ping` manca o non si puo' eseguire. E' questa macchina a
        // essere rotta, non il target: un'eccezione qualunque, che
        // `CheckMonitor::failed()` riporta senza segnare giu' niente.
        if (in_array($result->exitCode(), [126, 127], true)) {
            throw new RuntimeException('ping non eseguibile: '.trim($result->errorOutput()));
        }

        if (! $result->successful()) {
            $lastLine = Str::of($result->output()."\n".$result->errorOutput())->trim()->explode("\n")->last();

            throw new MonitorCheckFailed("ICMP {$host} — ".(filled($lastLine) ? trim($lastLine) : 'nessuna risposta'));
        }

        $elapsedMs = preg_match('/time[=<]([\d.]+)\s*ms/', $result->output(), $match)
            ? (int) round((float) $match[1])
            : null;

        if ($elapsedMs !== null) {
            $this->assertWithinThreshold($monitor, $elapsedMs, null);
        }

        return new ProbeResult($elapsedMs);
    }

    /**
     * La connessione verso un server di posta: TLS dal primo byte o in
     * chiaro, a seconda di `tls_mode`. Il nome atteso nel certificato e'
     * l'host, anche quando a TLS si passa dopo, con STARTTLS.
     *
     * @return resource
     *
     * @throws MonitorCheckFailed
     */
    private function openMailSocket(Monitor $monitor, string $protocol, int $startedAt)
    {
        $host = (string) $monitor->target;
        $scheme = $monitor->tls_mode === TlsMode::Tls ? 'tls' : 'tcp';

        return $this->openSocket($protocol, "{$scheme}://{$host}:{$monitor->port}", $monitor, $startedAt, ['peer_name' => $host]);
    }

    /**
     * Il passaggio a TLS dopo STARTTLS/STLS, con il certificato verificato:
     * un certificato scaduto sul server di posta e' un guasto, e i client lo
     * rifiutano come lo rifiutiamo noi.
     *
     * @param  resource  $socket
     *
     * @throws MonitorCheckFailed
     */
    private function startTls($socket, string $step, int $startedAt): void
    {
        error_clear_last();

        if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new MonitorCheckFailed(
                "{$step} — handshake TLS fallito".$this->lastErrorSuffix(),
                responseTimeMs: $this->elapsedMs($startedAt),
            );
        }
    }

    /**
     * Una riga di IMAP o POP3 che deve cominciare come atteso. IMAP puo'
     * mandare righe non taggate (`* CAPABILITY …`) prima della risposta a un
     * comando: si saltano, tranne per il saluto, che e' lui stesso non taggato.
     *
     * @param  resource  $socket
     *
     * @throws MonitorCheckFailed
     */
    private function mailboxCommand($socket, string $protocol, ?string $command, string $expected, int $startedAt): void
    {
        if ($command !== null) {
            fwrite($socket, "{$command}\r\n");
        }

        do {
            $line = $this->readLine($socket);
        } while ($line !== null && $command !== null && str_starts_with($line, '* '));

        if ($line === null || ! str_starts_with($line, $expected)) {
            $step = $command === null ? 'saluto' : Str::afterLast($command, ' ');

            throw new MonitorCheckFailed(
                "{$protocol} {$step} — ".($line ?? 'nessuna risposta').", atteso «{$expected}»",
                responseTimeMs: $this->elapsedMs($startedAt),
            );
        }
    }

    /**
     * Una connessione con il timeout del monitor, sia per aprirla sia per
     * ogni lettura: un server che accetta e poi tace deve fallire come uno
     * che non accetta.
     *
     * @param  array<string, mixed>  $ssl
     * @return resource
     *
     * @throws MonitorCheckFailed
     */
    private function openSocket(string $protocol, string $address, Monitor $monitor, int $startedAt, array $ssl = [])
    {
        error_clear_last();

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $monitor->timeout_seconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl]),
        );

        if ($socket === false) {
            // Un handshake TLS fallito lascia `errstr` vuoto: il motivo vero
            // e' solo nell'ultimo warning.
            throw new MonitorCheckFailed(
                "{$protocol} {$address} — ".($errstr ?: 'connessione fallita').$this->lastErrorSuffix(),
                responseTimeMs: $this->elapsedMs($startedAt),
            );
        }

        stream_set_timeout($socket, $monitor->timeout_seconds);

        return $socket;
    }

    /**
     * Un comando nel protocollo di Redis (RESP), non in quello inline: una
     * password con uno spazio dentro spezzerebbe la riga in due argomenti.
     *
     * @param  resource  $socket
     * @param  list<string>  $arguments
     *
     * @throws MonitorCheckFailed
     */
    private function redisCommand($socket, array $arguments, string $expected, int $startedAt): void
    {
        $command = '*'.count($arguments)."\r\n";

        foreach ($arguments as $argument) {
            $command .= '$'.strlen($argument)."\r\n{$argument}\r\n";
        }

        fwrite($socket, $command);

        $reply = $this->readLine($socket);

        if ($reply !== $expected) {
            throw new MonitorCheckFailed(
                "Redis {$arguments[0]} — ".($reply ?? 'nessuna risposta'),
                responseTimeMs: $this->elapsedMs($startedAt),
            );
        }
    }

    /**
     * Manda un comando SMTP (o nessuno, per leggere il saluto) e pretende il
     * codice atteso. Le risposte su piu' righe — `250-PIPELINING`,
     * `250-SIZE`… — finiscono alla riga col codice seguito da uno spazio.
     *
     * @param  resource  $socket
     *
     * @throws MonitorCheckFailed
     */
    private function smtpCommand($socket, ?string $command, int $expected, int $startedAt): void
    {
        if ($command !== null) {
            fwrite($socket, "{$command}\r\n");
        }

        do {
            $line = $this->readLine($socket);
        } while ($line !== null && ($line[3] ?? ' ') === '-');

        if ($line === null || (int) substr($line, 0, 3) !== $expected) {
            $step = $command === null ? 'saluto' : strtok($command, ' ');

            throw new MonitorCheckFailed(
                "SMTP {$step} — ".($line ?? 'nessuna risposta').", atteso {$expected}",
                responseTimeMs: $this->elapsedMs($startedAt),
            );
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readLine($socket): ?string
    {
        $line = fgets($socket);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /**
     * Il nome con cui ci presentiamo in `EHLO`. Qualche server rifiuta un
     * nome che non e' un dominio, e `APP_URL` e' l'unico che abbiamo.
     */
    private function heloName(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
    }

    private function lastErrorSuffix(): string
    {
        $message = error_get_last()['message'] ?? null;

        return $message === null ? '' : " ({$message})";
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
     * Giu' se almeno un figlio attivo e' giu'. Legge lo stato che i figli
     * hanno gia' scritto, senza ricontrollarli: ognuno ha i suoi retry, e
     * rifarli qui raddoppierebbe il traffico verso i target.
     *
     * Un figlio mai controllato o in pausa non conta: non sappiamo niente di
     * lui, e un gruppo vuoto non ha niente da dire, quindi e' su.
     *
     * Lo stato del gruppo segue quello dei figli con al massimo un suo
     * intervallo di ritardo, tranne quando parla per loro: allora un figlio
     * che cambia stato lo sveglia subito (`CheckMonitor::wakeGroup()`).
     *
     * @throws MonitorCheckFailed
     */
    private function checkGroup(Monitor $monitor): ProbeResult
    {
        $down = $monitor->children()
            ->where('is_active', true)
            ->where('is_up', false)
            ->orderBy('name')
            ->pluck('name');

        if ($down->isNotEmpty()) {
            throw new MonitorCheckFailed('Giù nel gruppo: '.$down->implode(', '));
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
