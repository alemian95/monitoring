<?php

namespace App\Filament\Resources\NotificationChannels;

use App\Filament\Resources\NotificationChannels\Pages\CreateNotificationChannel;
use App\Filament\Resources\NotificationChannels\Pages\EditNotificationChannel;
use App\Filament\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Filament\Resources\NotificationChannels\Schemas\NotificationChannelForm;
use App\Filament\Resources\NotificationChannels\Tables\NotificationChannelsTable;
use App\Models\NotificationChannel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class NotificationChannelResource extends Resource
{
    protected static ?string $model = NotificationChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $modelLabel = 'canale di notifica';

    protected static ?string $pluralModelLabel = 'canali di notifica';

    public static function form(Schema $schema): Schema
    {
        return NotificationChannelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationChannelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationChannels::route('/'),
            'create' => CreateNotificationChannel::route('/create'),
            'edit' => EditNotificationChannel::route('/{record}/edit'),
        ];
    }
}
