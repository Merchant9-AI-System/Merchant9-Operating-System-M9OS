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
                        Toggle::make('is_base_purity')
                            ->label('Gred Asas (pra-isi automatik)')
                            ->helperText('Aktif = sentiasa terpapar sbg baris tetap di Used Gold at HQ/GDN. Matikan utk varian pilihan manual sahaja (cth. 930, 916 - YS).')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
