<?php

namespace App\Filament\Resources\PhysicalGoldCategories\Schemas;

use App\Models\PhysicalGoldCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class PhysicalGoldCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextInput::make('code')
                            ->label('Kod Kategori')
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('name')
                            ->label('Nama Kategori')
                            ->required(),
                        Select::make('value_mode')
                            ->label('Mod Nilai')
                            ->options([
                                PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY => 'Gross x Ketulenan',
                                PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE => 'Payable / Receivable',
                            ])
                            ->required(),
                        Toggle::make('requires_branch')->label('Perlukan Cawangan'),
                        Toggle::make('requires_supplier')->label('Perlukan Supplier'),
                        Toggle::make('requires_purity')->label('Perlukan Ketulenan'),
                        Toggle::make('requires_date_range')->label('Perlukan Julat Tarikh'),
                        Toggle::make('include_in_physical_total')->label('Kira dlm Jumlah Fizikal')->default(true),
                        Toggle::make('is_deduction')->label('Tolakan (bukan tambahan)'),
                        TextInput::make('sort_order')
                            ->label('Susunan Papar')
                            ->numeric()
                            ->default(0),
                        Toggle::make('active')->label('Aktif')->default(true),
                    ]),
            ]);
    }
}
