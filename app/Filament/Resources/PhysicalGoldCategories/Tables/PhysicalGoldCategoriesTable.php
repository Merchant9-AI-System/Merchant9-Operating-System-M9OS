<?php

namespace App\Filament\Resources\PhysicalGoldCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhysicalGoldCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('code')->label('Kod')->searchable(),
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('value_mode')->label('Mod Nilai'),
                IconColumn::make('requires_branch')->label('Cawangan')->boolean()->toggleable(),
                IconColumn::make('requires_supplier')->label('Supplier')->boolean()->toggleable(),
                IconColumn::make('requires_purity')->label('Ketulenan')->boolean()->toggleable(),
                IconColumn::make('include_in_physical_total')->label('Kira dlm Jumlah')->boolean(),
                IconColumn::make('active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }
}
