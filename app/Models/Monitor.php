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
    public function recordCheck(bool $isUp): void
    {
        $this->checks()->create([
            'is_up' => $isUp,
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
        [$step, $unit] = $range->bucket();

        $rows = $this->checks()
            ->where('checked_at', '>=', $since)
            ->selectRaw('strftime(?, checked_at) as bucket, count(*) as total, sum(is_up) as up', [$range->sqlFormat()])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = collect();
        $cursor = $since->copy();

        while ($cursor <= now()) {
            $key = $cursor->format($range->carbonFormat());
            $row = $rows->get($key);

            $series[$cursor->toDateTimeString()] = $row === null
                ? null
                : round($row->up / $row->total * 100, 2);

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
            'expected_status' => 'integer',
            'timeout_seconds' => 'integer',
            'interval_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_up' => 'boolean',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
