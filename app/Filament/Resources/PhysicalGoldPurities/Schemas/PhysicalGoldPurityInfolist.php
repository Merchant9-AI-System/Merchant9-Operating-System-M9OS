<?php

namespace App\Filament\Resources\PhysicalGoldPurities\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PhysicalGoldPurityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ketulenan Emas')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('code')->label('Kod'),
                                TextEntry::make('factor')->label('Faktor Tulen')->numeric(4),
                                TextEntry::make('sort_order')->label('Susunan'),
                                IconEntry::make('active')->label('Aktif')->boolean(),
                            ]),
                    ]),
            ]);
    }
}
