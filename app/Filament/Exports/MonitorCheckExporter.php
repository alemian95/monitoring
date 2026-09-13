<?php

namespace App\Filament\Exports;

use App\Models\MonitorCheck;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class MonitorCheckExporter extends Exporter
{
    protected static ?string $model = MonitorCheck::class;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('monitor.name')
                ->label('Target'),

            ExportColumn::make('monitor.target')
                ->label('URL / host'),

            ExportColumn::make('checked_at')
                ->label('Controllato il'),

            ExportColumn::make('is_up')
                ->label('Stato')
                ->formatStateUsing(fn (bool $state): string => $state ? 'su' : 'giù'),

            ExportColumn::make('status_code')
                ->label('Status'),

            ExportColumn::make('response_time_ms')
                ->label('Tempo di risposta (ms)'),

            ExportColumn::make('failure_reason')
                ->label('Errore'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export dello storico completato: '.Str::of('riga')->plural($export->successful_rows).' esportate.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('riga')->plural($failedRowsCount).' non esportate.';
        }

        return $body;
    }
}
