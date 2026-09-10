<?php

namespace App\Filament\Resources\Monitors;

use App\Filament\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Filament\Resources\Monitors\Pages\ListMonitors;
use App\Filament\Resources\Monitors\Schemas\MonitorForm;
use App\Filament\Resources\Monitors\Tables\MonitorsTable;
use App\Models\Monitor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MonitorResource extends Resource
{
    protected static ?string $model = Monitor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return MonitorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MonitorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Aggiunge l'uptime delle ultime 24 ore come aggregato, cosi' la colonna in
     * tabella costa una subquery invece di una query per riga.
     *
     * `is_up` e' 0/1, quindi la sua media e' direttamente la percentuale di
     * check riusciti, una volta moltiplicata per cento.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withAvg(
                ['checks as uptime_24h' => fn (Builder $query): Builder => $query
                    ->where('checked_at', '>=', now()->subDay())],
                'is_up',
            );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonitors::route('/'),
            'create' => CreateMonitor::route('/create'),
            'edit' => EditMonitor::route('/{record}/edit'),
        ];
    }
}
