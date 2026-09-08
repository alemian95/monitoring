<?php

namespace App\Filament\Resources\Monitors\Schemas;

use App\Enums\MonitorType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MonitorForm
{
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
                    ->label(fn (Get $get): string => self::isType($get, MonitorType::Tcp) ? 'Host o IP' : 'URL')
                    ->required(),
                TextInput::make('port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->required(fn (Get $get): bool => self::isType($get, MonitorType::Tcp))
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Tcp)),
                TextInput::make('expected_status')
                    ->numeric()
                    ->default(200)
                    ->required()
                    ->visible(fn (Get $get): bool => self::isType($get, MonitorType::Http)),
                TextInput::make('interval_minutes')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                TextInput::make('timeout_seconds')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30)
                    ->default(10)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    /**
     * `options(MonitorType::class)` registra un EnumStateCast sul Select: lo
     * stato di `type` è quindi sempre un'istanza di `MonitorType`, sia in
     * creazione sia in modifica. `Get::enum()` normalizza comunque anche
     * l'eventuale forma stringa, a scopo difensivo.
     */
    private static function isType(Get $get, MonitorType $type): bool
    {
        return $get->enum('type', MonitorType::class, isNullable: true) === $type;
    }
}
