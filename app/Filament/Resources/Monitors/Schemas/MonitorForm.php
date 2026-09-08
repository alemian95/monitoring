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
                    ->default(10)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    /**
     * Il campo `type` può contenere sia l'enum (record già castato) sia la sua
     * stringa (stato appena selezionato nel Select): normalizza il confronto.
     */
    private static function isType(Get $get, MonitorType $type): bool
    {
        return $get->enum('type', MonitorType::class, isNullable: true) === $type;
    }
}
