<?php

namespace App\Filament\Resources\Monitors\Schemas;

use App\Enums\DnsRecordType;
use App\Enums\MonitorType;
use App\Enums\MonitorVisibility;
use App\Enums\SmtpSecurity;
use App\Models\NotificationChannel;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MonitorForm
{
    private const HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** I metodi con un corpo: per gli altri il campo non ha senso. */
    private const METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** I tipi il cui indirizzo contiene una password. */
    private const USES_CONNECTION_URL = [MonitorType::Database, MonitorType::Redis];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('type')
                    ->options(MonitorType::class)
                    ->default(MonitorType::Http)
                    ->required()
                    ->live(),
                TextInput::make('target')
                    ->label(fn (Get $get): string => match (true) {
                        self::isType($get, MonitorType::Tcp, MonitorType::Smtp) => 'Host o IP',
                        self::isType($get, MonitorType::Dns) => 'Nome da risolvere',
                        default => 'URL',
                    })
                    // Un push monitor sorveglia un job, non un indirizzo: non
                    // c'e' niente da contattare, il nome basta a identificarlo.
                    // Database e Redis hanno un indirizzo, ma con dentro la
                    // password: sta in `connection_url`, cifrato.
                    ->required(fn (Get $get): bool => ! self::isType($get, MonitorType::Push, ...self::USES_CONNECTION_URL))
                    ->visible(fn (Get $get): bool => ! self::isType($get, MonitorType::Push, ...self::USES_CONNECTION_URL)),
                TextInput::make('connection_url')
                    ->label('Connection URL')
                    ->placeholder(fn (Get $get): string => self::isType($get, MonitorType::Redis)
                        ? 'redis://:password@host:6379'
                        : 'mysql://utente:password@host:3306/database')
                    ->helperText(fn (Get $get): string => (self::isType($get, MonitorType::Redis)
                        ? 'redis, o rediss per TLS.'
                        : 'mysql, mariadb, postgres o sqlsrv.')
                        .' Caratteri speciali della password in percent-encoding. Salvato cifrato.')
                    ->password()
                    ->revealable()
                    ->regex(fn (Get $get): string => self::isType($get, MonitorType::Redis)
                        ? '#^rediss?://#'
                        : '#^(mysql|mariadb|postgres|postgresql|pgsql|sqlsrv|mssql)://#')
                    ->required(fn (Get $get): bool => self::isType($get, ...self::USES_CONNECTION_URL))
                    ->visible(fn (Get $get): bool => self::isType($get, ...self::USES_CONNECTION_URL)),
                Select::make('http_method')
                    ->label('Metodo')
                    ->options(array_combine(self::HTTP_METHODS, self::HTTP_METHODS))
                    ->default('GET')
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http)),
                TextInput::make('port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->required(fn (Get $get): bool => self::isType($get, MonitorType::Tcp, MonitorType::Smtp))
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Tcp, MonitorType::Smtp)),
                Select::make('smtp_security')
                    ->label('Sicurezza')
                    ->helperText('Di solito: nessuna sulla 25, STARTTLS sulla 587, TLS implicito sulla 465.')
                    ->options(SmtpSecurity::options())
                    ->default(SmtpSecurity::Starttls->value)
                    ->required(fn (Get $get): bool => self::isType($get, MonitorType::Smtp))
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Smtp)),
                Select::make('dns_record_type')
                    ->label('Tipo di record')
                    ->options(DnsRecordType::class)
                    ->default(DnsRecordType::A)
                    ->required(fn (Get $get): bool => self::isType($get, MonitorType::Dns))
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Dns)),
                TagsInput::make('expected_statuses')
                    ->label('Status accettati')
                    ->helperText('Invio per aggiungerne uno. Vuoto vale 200.')
                    ->placeholder('200')
                    ->default([200])
                    ->nestedRecursiveRules(['integer', 'between:100,599'])
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http)),
                TextInput::make('expected_body_contains')
                    ->label(fn (Get $get): string => self::isType($get, MonitorType::Dns)
                        ? 'Il record deve contenere'
                        : 'Il corpo deve contenere')
                    ->helperText(fn (Get $get): string => self::isType($get, MonitorType::Dns)
                        ? 'Opzionale. Un record che non contiene questo valore conta come fallimento.'
                        : 'Opzionale. Un 200 che non contiene questo testo conta come fallimento.')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http, MonitorType::Dns)),
                Toggle::make('invert_keyword')
                    ->label('Il testo NON deve comparire')
                    ->helperText('Per «Errore» o «Manutenzione» in una pagina che risponde comunque 200.')
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http, MonitorType::Dns)),
                TextInput::make('json_path')
                    ->label('Campo JSON')
                    ->placeholder('data.status')
                    ->helperText('Opzionale. Dot notation; il campo deve esistere nella risposta.')
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http)),
                TextInput::make('json_expected_value')
                    ->label('Valore atteso del campo')
                    ->helperText('Opzionale. Confronto esatto: true, 42, "ok" si scrivono true, 42, ok.')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http) && filled($get('json_path'))),
                KeyValue::make('http_headers')
                    ->label('Header')
                    ->keyLabel('Nome')
                    ->valueLabel('Valore')
                    ->helperText('Salvati cifrati: qui dentro ci finiscono i token.')
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http)),
                Textarea::make('http_body')
                    ->label('Corpo della richiesta')
                    ->rows(3)
                    ->helperText('Il content-type va dichiarato negli header.')
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http)
                        && in_array($get('http_method'), self::METHODS_WITH_BODY, true)),
                TextInput::make('grace_minutes')
                    ->label('Attendo un ping ogni (minuti)')
                    ->helperText('Passati questi minuti senza ping, il job risulta giu.')
                    ->numeric()
                    ->minValue(1)
                    ->default(60)
                    ->required(fn (Get $get): bool => self::isType($get, MonitorType::Push))
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Push)),
                TextInput::make('interval_minutes')
                    ->label(fn (Get $get): string => self::isType($get, MonitorType::Push)
                        ? 'Ogni quanto verifico il ritardo (minuti)'
                        : 'Intervallo (minuti)')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                TextInput::make('timeout_seconds')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30)
                    ->default(10)
                    ->required()
                    ->visible(fn (Get $get): bool => ! self::isType($get, MonitorType::Push)),
                TextInput::make('max_response_time_ms')
                    ->label('Tempo di risposta massimo (ms)')
                    ->helperText('Opzionale. Oltre la soglia il check conta come fallito.')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn (Get $get): bool => ! self::isType($get, MonitorType::Push)),
                Toggle::make('is_active')
                    ->default(true),
                Toggle::make('is_inverted')
                    ->label('Invertito (upside-down)')
                    ->helperText('Giù quando risponde, su quando non risponde: per ciò che deve restare irraggiungibile.')
                    ->visible(fn (Get $get): bool => ! self::isType($get, MonitorType::Push)),
                Select::make('visibility')
                    ->label('Pagina di stato')
                    ->helperText('Chi puo vedere questo servizio fuori dal pannello.')
                    ->options(MonitorVisibility::options())
                    ->default(MonitorVisibility::Private)
                    ->required(),
                Select::make('notificationChannels')
                    ->label('Canali di notifica')
                    ->helperText('Dove arrivano gli alert di questo monitor. Nessuno: gli alert restano solo nei log.')
                    ->relationship('notificationChannels', 'name')
                    ->multiple()
                    ->preload()
                    ->default(fn (): array => NotificationChannel::query()->isDefault()->pluck('id')->all()),
            ]);
    }

    /**
     * `options(MonitorType::class)` registra un EnumStateCast sul Select: lo
     * stato di `type` è quindi sempre un'istanza di `MonitorType`, sia in
     * creazione sia in modifica. `Get::enum()` normalizza comunque anche
     * l'eventuale forma stringa, a scopo difensivo.
     */
    private static function isType(Get $get, MonitorType ...$types): bool
    {
        return in_array($get->enum('type', MonitorType::class, isNullable: true), $types, true);
    }
}
