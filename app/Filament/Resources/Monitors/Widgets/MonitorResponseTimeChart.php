<?php

namespace App\Filament\Resources\Monitors\Widgets;

use App\Enums\UptimeRange;
use App\Models\Monitor;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class MonitorResponseTimeChart extends ChartWidget
{
    /**
     * Iniettato dalla pagina della Resource, che passa il record ai suoi
     * widget tramite `getWidgetData()`.
     */
    public ?Monitor $record = null;

    public ?string $filter = 'month';

    protected ?string $heading = 'Tempo di risposta';

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
        $series = $this->record?->responseTimeSeries($range) ?? collect();

        return [
            'datasets' => [
                [
                    'label' => 'Millisecondi',
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
     * Asse libero verso l'alto, al contrario di quello dell'uptime: i
     * millisecondi non hanno un tetto, e fissarne uno nasconderebbe proprio il
     * picco che si sta cercando. Resta ancorato a zero, perche' una scala che
     * parte da 180 ms fa sembrare una catastrofe un'oscillazione di venti.
     *
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
            ],
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
