<?php

namespace App\Filament\Resources\PhysicalGoldPurities\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class PhysicalGoldPurityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(4)
                    ->schema([
                        TextInput::make('code')
                            ->label('Kod Ketulenan')
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('factor')
                            ->label('Faktor Tulen')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(1)
                            ->required(),
                        TextInput::make('sort_order')
                            ->label('Susunan Papar')
                            ->numeric()
                            ->default(0),
                        Toggle::make('active')->label('Aktif')->default(true),
                    ]),
            ]);
    }
}
