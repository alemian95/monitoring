<?php

namespace App\Models;

use App\Enums\MonitorType;
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
     * Percentuale di check riusciti per giorno, dal piu' vecchio al piu'
     * recente, con i giorni senza dati a `null` e non a zero: un giorno in cui
     * il monitor era in pausa non e' un giorno di downtime, e disegnarlo a zero
     * sarebbe una bugia.
     *
     * @return Collection<string, float|null> chiave `Y-m-d`
     */
    public function uptimeByDay(int $days = 30): Collection
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = $this->checks()
            ->where('checked_at', '>=', $from)
            ->selectRaw('date(checked_at) as day, count(*) as total, sum(is_up) as up')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        return collect(range(0, $days - 1))
            ->mapWithKeys(function (int $offset) use ($from, $rows): array {
                $day = $from->copy()->addDays($offset)->format('Y-m-d');
                $row = $rows->get($day);

                return [$day => $row === null
                    ? null
                    : round($row->up / $row->total * 100, 2)];
            });
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
