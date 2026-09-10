<?php

namespace App\Filament\Resources\Monitors\Widgets;

use App\Enums\UptimeRange;
use App\Models\Monitor;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class MonitorUptimeChart extends ChartWidget
{
    /**
     * Iniettato dalla pagina della Resource, che passa il record ai suoi
     * widget tramite `getWidgetData()`.
     */
    public ?Monitor $record = null;

    public ?string $filter = 'month';

    protected ?string $heading = 'Uptime';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return UptimeRange::options();
    }

    /**
     * @return array{datasets: array<array<string, mixed>>, labels: array<string>}
     */
    protected function getData(): array
    {
        $range = UptimeRange::tryFrom($this->filter ?? '') ?? UptimeRange::Month;
        $series = $this->record?->uptimeSeries($range) ?? collect();

        return [
            'datasets' => [
                [
                    'label' => 'Uptime %',
                    'data' => $series->values()->all(),
                    'fill' => 'start',
                    'tension' => 0.2,
                    'spanGaps' => $range->connectsGaps(),
                ],
            ],
            'labels' => $series->keys()
                ->map(fn (string $at): string => Carbon::parse($at)->format($range->labelFormat()))
                ->all(),
        ];
    }

    /**
     * L'uptime e' una percentuale: il suo dominio e' [0, 100] e va fissato.
     * Lasciando auto-scalare l'asse, Chart.js mostrerebbe spazio sopra il 100%
     * che non puo' esistere, e con una sola serie piatta al 100% arriverebbe a
     * inventare tick a 100,5.
     *
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'min' => 0,
                    'max' => 100,
                    'ticks' => ['stepSize' => 25],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
            ],
            // Il margine sta sul canvas, non sulla scala: alzare `max` sopra
            // 100 mostrerebbe un dominio che una percentuale non ha, mentre il
            // padding lascia solo respiro al marker del 100%, che altrimenti il
            // bordo taglia a metà.
            'layout' => [
                'padding' => ['top' => 12],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
