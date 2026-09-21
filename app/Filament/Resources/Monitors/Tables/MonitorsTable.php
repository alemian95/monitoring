<?php

namespace App\Filament\Resources\Monitors\Tables;

use App\Enums\MonitorVisibility;
use App\Enums\UptimeLevel;
use App\Filament\Exports\MonitorCheckExporter;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class MonitorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('target')
                    ->searchable()
                    ->limit(40),
                IconColumn::make('is_up')
                    ->label('Stato')
                    ->boolean()
                    ->placeholder('mai controllato'),
                TextColumn::make('uptime_24h')
                    ->label('Uptime 24h')
                    ->placeholder('—')
                    ->sortable()
                    ->formatStateUsing(fn (float $state): string => round($state * 100, 2).'%')
                    // `uptime_24h` arriva come frazione dalla subquery, le
                    // soglie ragionano in percentuale.
                    ->color(fn (?float $state): string => UptimeLevel::for($state === null ? null : $state * 100)->color()),

                ToggleColumn::make('is_active')
                    ->label('Attivo'),
                TextColumn::make('visibility')
                    ->label('Pagina di stato')
                    ->badge()
                    ->formatStateUsing(fn (MonitorVisibility $state): string => $state->label())
                    ->toggleable(),
                TextColumn::make('last_checked_at')
                    ->label('Ultimo check')
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('last_failure_reason')
                    ->label('Ultimo errore')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Esporta storico')
                    ->exporter(MonitorCheckExporter::class)
                    ->icon('heroicon-o-arrow-down-tray'),
            ])
            ->recordActions([
                Action::make('signedLink')
                    ->label('Link firmato')
                    ->icon('heroicon-o-link')
                    ->visible(fn (Monitor $record): bool => $record->visibility === MonitorVisibility::Signed)
                    ->schema([
                        Select::make('days')
                            ->label('Scadenza')
                            ->options([
                                7 => '7 giorni',
                                30 => '30 giorni',
                                365 => 'Un anno',
                                0 => 'Nessuna scadenza',
                            ])
                            ->default(30)
                            ->required(),
                    ])
                    ->action(function (array $data, Monitor $record): void {
                        $days = (int) $data['days'];

                        Notification::make()
                            ->title('Link generato')
                            ->body($record->statusUrl($days === 0 ? null : $days))
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Action::make('checkNow')
                    ->label('Controlla ora')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Monitor $record): void {
                        CheckMonitor::dispatch($record);
                    })
                    ->successNotificationTitle('Check richiesto'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
