<?php

namespace App\Filament\Resources\Monitors\Widgets;

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

    protected ?string $heading = 'Uptime — ultimi 30 giorni';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{datasets: array<array<string, mixed>>, labels: array<string>}
     */
    protected function getData(): array
    {
        $days = $this->record?->uptimeByDay() ?? collect();

        return [
            'datasets' => [
                [
                    'label' => 'Uptime %',
                    'data' => $days->values()->all(),
                    'fill' => 'start',
                    'tension' => 0.2,
                    'spanGaps' => false,
                ],
            ],
            'labels' => $days->keys()
                ->map(fn (string $day): string => Carbon::parse($day)->format('d/m'))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
