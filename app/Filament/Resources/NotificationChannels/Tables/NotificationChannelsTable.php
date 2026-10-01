<?php

namespace App\Filament\Resources\NotificationChannels\Tables;

use App\Enums\NotificationChannelType;
use App\Jobs\SendAlert;
use App\Models\NotificationChannel;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class NotificationChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (NotificationChannelType $state): string => $state->label()),
                IconColumn::make('is_default')
                    ->label('Predefinito')
                    ->boolean(),
                TextColumn::make('monitors_count')
                    ->label('Monitor')
                    ->counts('monitors'),
            ])
            ->recordActions([
                // `handle()` diretto, non in coda e nemmeno `dispatchSync`: chi
                // preme il pulsante vuole sapere adesso se il token e' giusto, e
                // un test fallito non e' un job fallito — non deve finire in
                // `failed_jobs` ne' far scattare il `/fail` dell'heartbeat.
                Action::make('sendTest')
                    ->label('Invia test')
                    ->icon('heroicon-o-paper-airplane')
                    ->action(function (NotificationChannel $record): void {
                        try {
                            (new SendAlert($record, "🧪 **Test** — il canale {$record->name} funziona."))->handle();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Consegna fallita')
                                ->body($exception->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Messaggio di test inviato')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
