<?php

namespace App\Filament\Widgets;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UptimeOverview extends StatsOverviewWidget
{
    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $active = Monitor::where('is_active', true);

        return [
            Stat::make('Target su', (clone $active)->where('is_up', true)->count())
                ->description('rispondono come atteso')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Target giù', (clone $active)->where('is_up', false)->count())
                ->description('non rispondono')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),

            $this->uptimeStat(),
        ];
    }

    /**
     * Uptime aggregato delle ultime 24 ore, con la sparkline dei singoli
     * bucket orari accanto al numero.
     */
    private function uptimeStat(): Stat
    {
        $checks = MonitorCheck::where('checked_at', '>=', now()->subDay())->get();

        $percentage = $checks->isEmpty()
            ? null
            : round($checks->where('is_up', true)->count() / $checks->count() * 100, 2);

        // ponytail: bucket orari calcolati in PHP sulle sole 24h (poche migliaia
        // di righe). Con molti target o finestre piu' lunghe, spostare in SQL.
        $hourly = $checks
            ->groupBy(fn (MonitorCheck $check): string => $check->checked_at->format('Y-m-d H'))
            ->map(fn ($bucket): float => round($bucket->where('is_up', true)->count() / $bucket->count() * 100, 2))
            ->values();

        return Stat::make('Uptime 24h', $percentage === null ? '—' : "{$percentage}%")
            ->description($checks->isEmpty() ? 'nessun check registrato' : "{$checks->count()} check")
            ->chart($hourly->count() > 1 ? $hourly->all() : [0, 0])
            ->color($percentage === null ? 'gray' : ($percentage >= 99 ? 'success' : ($percentage >= 95 ? 'warning' : 'danger')));
    }
}
