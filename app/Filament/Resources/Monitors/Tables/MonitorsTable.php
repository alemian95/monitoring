<?php

namespace App\Filament\Resources\Monitors\Tables;

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                ToggleColumn::make('is_active')
                    ->label('Attivo'),
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
            ->recordActions([
                Action::make('checkNow')
                    ->label('Controlla ora')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Monitor $record): void {
                        CheckMonitor::dispatch($record);
                    })
                    ->successNotificationTitle('Check accodato'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
