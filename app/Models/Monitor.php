<?php

namespace App\Models;

use App\Enums\DnsRecordType;
use App\Enums\MonitorType;
use App\Enums\MonitorVisibility;
use App\Enums\SmtpSecurity;
use App\Enums\UptimeRange;
use App\Support\Incident;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * I default del database, anche in memoria: un monitor appena creato e
     * non riletto avrebbe `null`, e il probe confronta questi booleani in
     * modo stretto.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_inverted' => false,
        'invert_keyword' => false,
        'silences_children' => false,
    ];

    /**
     * Il token di ping nasce con il monitor, qualunque sia il tipo: cosi'
     * cambiare tipo a un monitor esistente non richiede di generarlo al volo, e
     * `pingUrl()` resta una lettura pura.
     */
    protected static function booted(): void
    {
        static::creating(function (self $monitor): void {
            $monitor->ping_token ??= Str::random(40);
        });

        // Qui e non nel form, cosi' vale per ogni via da cui nasce un monitor.
        // Il form li mostra gia' selezionati e, al salvataggio, sincronizza
        // la scelta dell'utente sopra questa.
        static::created(function (self $monitor): void {
            $monitor->notificationChannels()->attach(NotificationChannel::query()->isDefault()->pluck('id'));
        });
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    /**
     * @return HasMany<Monitor, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Se gli alert di giu' e su dei figli passano da qui invece che da loro.
     *
     * Solo un gruppo attivo: un gruppo in pausa non controlla nessuno, e se
     * zittisse lo stesso i figli un guasto non lo direbbe piu' nessuno. Il
     * tipo e' nella regola perche' cambiare tipo a un gruppo lascia la
     * colonna com'era.
     */
    public function speaksForChildren(): bool
    {
        return $this->type === MonitorType::Group && $this->is_active && $this->silences_children;
    }

    /**
     * @return BelongsToMany<NotificationChannel, $this>
     */
    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class);
    }

    /**
     * @return HasMany<MonitorCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * Registra l'esito di un ciclo di check: una riga per ciclo, non per
     * tentativo, cosi' i 4 retry di un singolo fallimento restano un evento.
     */
    public function recordCheck(
        bool $isUp,
        ?int $statusCode = null,
        ?int $responseTimeMs = null,
        ?string $failureReason = null,
    ): void {
        $this->checks()->create([
            'is_up' => $isUp,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTimeMs,
            'failure_reason' => $failureReason,
            'checked_at' => now(),
        ]);
    }

    /**
     * Il link alla pagina di stato di questo servizio, firmato.
     *
     * La firma copre l'URL intero, quindi il link di un servizio non ne apre un
     * altro. Senza giorni il link non scade: e' una scelta, non una
     * dimenticanza, e va fatta da chi lo genera.
     *
     * Attenzione: la firma e' calcolata sull'URL assoluto, quindi in produzione
     * `APP_URL` deve essere quello vero o i link non valideranno.
     */
    public function statusUrl(?int $expiresInDays = null): string
    {
        return $expiresInDays === null
            ? URL::signedRoute('status.monitor', $this)
            : URL::temporarySignedRoute('status.monitor', now()->addDays($expiresInDays), $this);
    }

    /**
     * L'indirizzo che il job esterno deve chiamare per dire "sono vivo", e con
     * il suffisso `/fail` per dire "sono andato male".
     *
     * Un token e non un URL firmato: questo indirizzo finisce nella crontab di
     * qualcun altro, e se trapela deve poter essere revocato senza toccare
     * `APP_KEY`. Rigenerarlo invalida il vecchio, ed e' l'unica via.
     */
    public function pingUrl(bool $failure = false): string
    {
        return route('monitor.ping', ['token' => $this->ping_token] + ($failure ? ['status' => 'fail'] : []));
    }

    /**
     * Percentuale di check riusciti sull'intera finestra, o `null` se in quella
     * finestra non c'e' nemmeno un check.
     *
     * Non e' la media di `uptimeSeries()`: i bucket hanno un numero di check
     * diverso l'uno dall'altro, e mediare percentuali gia' mediate darebbe piu'
     * peso a un'ora con due check che a una con sessanta.
     */
    public function uptimePercentage(UptimeRange $range = UptimeRange::Month): ?float
    {
        $average = $this->checks()
            ->where('checked_at', '>=', $range->since())
            ->avg('is_up');

        return $average === null ? null : round((float) $average * 100, 2);
    }

    /**
     * I disservizi della finestra, dal piu' recente.
     *
     * Lo storico e' una colonna di booleani: qui diventa una serie di eventi,
     * che e' come li racconta chi li legge — «giu' 47 minuti per timeout», non
     * quarantasette righe.
     *
     * Torna solo le transizioni e non tutte le righe: su un mese a un check al
     * minuto sarebbero oltre quarantamila, mentre i cambi di stato si contano
     * sulle dita. `lag()` guarda la riga precedente; la prima della finestra
     * non ne ha una, ed entra comunque perche' un incidente puo' essere gia'
     * cominciato prima.
     *
     * ponytail: sintassi SQLite, come `UptimeRange::sqlFormat()`. Se un
     * monitor viene messo in pausa mentre e' giu', l'incidente resta aperto
     * fino alla ripresa: non abbiamo osservato nessuna guarigione, e inventarne
     * una sarebbe peggio.
     *
     * @return Collection<int, Incident>
     */
    public function incidents(UptimeRange $range = UptimeRange::Month): Collection
    {
        $transitions = DB::query()
            ->fromSub(
                MonitorCheck::query()
                    ->where('monitor_id', $this->id)
                    ->where('checked_at', '>=', $range->since())
                    ->selectRaw('checked_at, is_up, failure_reason, lag(is_up) over (order by checked_at, id) as previous_is_up')
                    ->toBase(),
                'transitions',
            )
            ->whereColumn('is_up', '!=', 'previous_is_up')
            ->orWhereNull('previous_is_up')
            ->orderBy('checked_at')
            ->get();

        $incidents = collect();
        $open = null;

        foreach ($transitions as $transition) {
            if (! $transition->is_up && $open === null) {
                $open = new Incident(
                    Carbon::parse($transition->checked_at),
                    null,
                    $transition->failure_reason,
                );
            } elseif ($transition->is_up && $open !== null) {
                $incidents->push(new Incident($open->startedAt, Carbon::parse($transition->checked_at), $open->reason));
                $open = null;
            }
        }

        if ($open !== null) {
            $incidents->push($open);
        }

        return $incidents->reverse()->values();
    }

    /**
     * Il percentile dei tempi di risposta in una finestra, in millisecondi, o
     * `null` se i campioni non bastano a dirne qualcosa.
     *
     * Percentile e non media: la media la abbassa la maggioranza dei check
     * veloci, e un target che rallenta lo fa prima sulla coda. SQLite non ha
     * una funzione di percentile, quindi si ordina e si prende la riga
     * all'indice giusto — due query, su una finestra gia' ristretta.
     */
    public function responseTimePercentile(
        float $percentile,
        Carbon $since,
        ?Carbon $until = null,
        int $minimumSamples = 1,
    ): ?int {
        $query = $this->checks()
            ->whereNotNull('response_time_ms')
            ->where('checked_at', '>=', $since)
            ->when($until, fn (Builder $query) => $query->where('checked_at', '<', $until));

        $total = (clone $query)->count();

        if ($total === 0 || $total < $minimumSamples) {
            return null;
        }

        $offset = min((int) floor($total * $percentile), $total - 1);

        return $query->orderBy('response_time_ms')->offset($offset)->value('response_time_ms');
    }

    /**
     * Percentuale di check riusciti per bucket temporale, dal piu' vecchio al
     * piu' recente. I bucket senza dati valgono `null` e non zero: un
     * intervallo in cui il monitor era in pausa non e' downtime, e disegnarlo a
     * zero sarebbe una bugia.
     *
     * @return Collection<string, float|null> chiave = istante iniziale del bucket
     */
    public function uptimeSeries(UptimeRange $range = UptimeRange::Month): Collection
    {
        $since = $range->since();

        $rows = $this->checks()
            ->where('checked_at', '>=', $since)
            ->selectRaw('strftime(?, checked_at) as bucket, count(*) as total, sum(is_up) as up', [$range->sqlFormat()])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return $this->bucketSeries($range, $rows, fn (object $row): float => round($row->up / $row->total * 100, 2));
    }

    /**
     * Tempo di risposta medio per bucket, in millisecondi. Stessa forma e
     * stessa semantica dei buchi di `uptimeSeries()`, cosi' i due grafici si
     * leggono uno sotto l'altro.
     *
     * Le righe senza tempo — un timeout non ne ha uno — sono escluse invece di
     * valere zero: una media abbassata da un target irraggiungibile
     * racconterebbe l'opposto di quello che e' successo.
     *
     * @return Collection<string, float|null> chiave = istante iniziale del bucket
     */
    public function responseTimeSeries(UptimeRange $range = UptimeRange::Month): Collection
    {
        $rows = $this->checks()
            ->where('checked_at', '>=', $range->since())
            ->whereNotNull('response_time_ms')
            ->selectRaw('strftime(?, checked_at) as bucket, avg(response_time_ms) as average', [$range->sqlFormat()])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return $this->bucketSeries($range, $rows, fn (object $row): float => round($row->average, 1));
    }

    /**
     * Cammina i bucket dell'intervallo dal piu' vecchio al piu' recente,
     * riempiendo con `null` quelli senza righe.
     *
     * Sta qui in comune perche' quel `null` e' la parte sottile: un intervallo
     * senza dati non e' downtime e non e' latenza zero, e le due serie devono
     * mentire — o non mentire — allo stesso modo.
     *
     * @param  Collection<string, object>  $rows
     * @param  callable(object): float  $value
     * @return Collection<string, float|null>
     */
    private function bucketSeries(UptimeRange $range, Collection $rows, callable $value): Collection
    {
        [$step, $unit] = $range->bucket();

        $series = collect();
        $cursor = $range->since()->copy();

        while ($cursor <= now()) {
            $row = $rows->get($cursor->format($range->carbonFormat()));

            $series[$cursor->toDateTimeString()] = $row === null ? null : $value($row);

            $cursor->add($unit, $step);
        }

        return $series;
    }

    /**
     * I monitor attivi da controllare adesso: mai controllati, o con la
     * scadenza già passata.
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now());
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'dns_record_type' => DnsRecordType::class,
            'smtp_security' => SmtpSecurity::class,
            'visibility' => MonitorVisibility::class,
            'port' => 'integer',
            'expected_statuses' => 'array',
            // Un header `Authorization` e' una credenziale: cifrata a riposo,
            // cosi' un dump del database non la regala.
            'http_headers' => 'encrypted:array',
            // Dentro c'e' la password del database o di Redis.
            'connection_url' => 'encrypted',
            'is_inverted' => 'boolean',
            'invert_keyword' => 'boolean',
            'silences_children' => 'boolean',
            'timeout_seconds' => 'integer',
            'max_response_time_ms' => 'integer',
            'interval_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_up' => 'boolean',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'certificate_expires_at' => 'datetime',
            'certificate_alerted_at' => 'datetime',
        ];
    }
}
