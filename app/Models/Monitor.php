<?php

namespace App\Models;

use App\Enums\MonitorType;
use App\Enums\UptimeRange;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    protected $guarded = [];

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
            'port' => 'integer',
            'expected_statuses' => 'array',
            'timeout_seconds' => 'integer',
            'max_response_time_ms' => 'integer',
            'interval_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_up' => 'boolean',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
