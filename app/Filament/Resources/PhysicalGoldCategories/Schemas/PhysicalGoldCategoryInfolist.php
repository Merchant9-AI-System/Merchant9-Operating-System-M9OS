<?php

namespace App\Filament\Resources\PhysicalGoldCategories\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PhysicalGoldCategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Kategori Emas Fizikal')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('code')->label('Kod'),
                                TextEntry::make('name')->label('Nama'),
                                TextEntry::make('value_mode')->label('Mod Nilai'),
                                TextEntry::make('sort_order')->label('Susunan'),
                                IconEntry::make('requires_branch')->label('Perlukan Cawangan')->boolean(),
                                IconEntry::make('requires_supplier')->label('Perlukan Supplier')->boolean(),
                                IconEntry::make('requires_purity')->label('Perlukan Ketulenan')->boolean(),
                                IconEntry::make('requires_date_range')->label('Perlukan Julat Tarikh')->boolean(),
                                IconEntry::make('include_in_physical_total')->label('Kira dlm Jumlah Fizikal')->boolean(),
                                IconEntry::make('is_deduction')->label('Tolakan')->boolean(),
                                IconEntry::make('active')->label('Aktif')->boolean(),
                            ]),
                    ]),
            ]);
    }
}
