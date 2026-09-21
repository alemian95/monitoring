<?php

namespace App\Filament\Resources\Monitors\Widgets;

use App\Enums\UptimeRange;
use App\Models\Monitor;
use Filament\Widgets\Widget;

/**
 * Lo storico letto come eventi: i due grafici accanto dicono *quanto*, questo
 * dice *quando* e *perche'*.
 *
 * Finestra fissa al mese, senza il filtro dei grafici: un elenco di disservizi
 * si guarda per sapere cos'e' successo di recente, e la finestra piu' larga li
 * contiene tutti.
 */
class MonitorIncidents extends Widget
{
    private const RANGE = UptimeRange::Month;

    /**
     * Iniettato dalla pagina della Resource, come per i grafici.
     */
    public ?Monitor $record = null;

    protected string $view = 'filament.resources.monitors.widgets.monitor-incidents';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'range' => self::RANGE,
            'incidents' => $this->record?->incidents(self::RANGE) ?? collect(),
        ];
    }
}
