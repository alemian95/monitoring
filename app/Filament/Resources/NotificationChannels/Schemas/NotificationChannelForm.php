<?php

namespace App\Filament\Resources\NotificationChannels\Schemas;

use App\Enums\NotificationChannelType as Type;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * I campi di `settings` cambiano col tipo. Quelli nascosti non vengono
 * salvati, quindi cambiare tipo a un canale non si porta dietro il token del
 * tipo precedente.
 */
class NotificationChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required(),
                Select::make('type')
                    ->label('Tipo')
                    ->options(Type::options())
                    ->default(Type::Discord->value)
                    ->required()
                    ->live(),

                self::setting('webhook_url', 'Webhook URL', [Type::Discord, Type::Slack, Type::Teams])->url(),
                self::setting('url', 'URL', [Type::Webhook])
                    ->url()
                    ->helperText('Riceve una POST JSON con `message` e `monitor`.'),
                self::setting('to', 'Destinatario', [Type::Email])->email(),
                self::setting('bot_token', 'Bot token', [Type::Telegram])->password()->revealable(),
                self::setting('chat_id', 'Chat ID', [Type::Telegram]),
                self::setting('server_url', 'Server', [Type::Ntfy, Type::Gotify])
                    ->url()
                    ->default('https://ntfy.sh'),
                self::setting('topic', 'Topic', [Type::Ntfy]),
                self::setting('token', 'Access token', [Type::Ntfy])
                    ->password()
                    ->revealable()
                    ->required(false)
                    ->helperText('Solo per topic protetti.'),
                self::setting('app_token', 'Application token', [Type::Gotify, Type::Pushover])->password()->revealable(),
                self::setting('user_key', 'User key', [Type::Pushover]),

                Toggle::make('is_default')
                    ->label('Predefinito')
                    ->helperText('Selezionato in automatico sui nuovi monitor.'),
            ]);
    }

    /**
     * Un campo di `settings`, obbligatorio e visibile solo per i tipi che lo
     * usano.
     *
     * @param  list<Type>  $types
     */
    private static function setting(string $key, string $label, array $types): TextInput
    {
        $isUsed = fn (Get $get): bool => in_array($get->enum('type', Type::class, isNullable: true), $types, true);

        return TextInput::make("settings.{$key}")
            ->label($label)
            ->required($isUsed)
            ->visible($isUsed);
    }
}
