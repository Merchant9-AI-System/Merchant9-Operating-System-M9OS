<?php

namespace App\Filament\Resources\BudgetPeriods\Tables;

use App\Models\BudgetPeriod;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BudgetPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_label')->label('Bulan')->sortable(),
                TextColumn::make('category.Description')->label('Kategori')->placeholder('Keseluruhan'),
                TextColumn::make('budget_amount')->label('Budget')->money('MYR')->sortable(),
                TextColumn::make('spent_amount')->label('Spent')->state(fn (BudgetPeriod $r) => $r->spent_amount)->money('MYR'),
                TextColumn::make('usage_percent')->label('Guna')
                    ->state(fn (BudgetPeriod $r) => round($r->usage_percent, 1).'%')
                    ->badge()
                    ->color(fn (BudgetPeriod $r) => $r->isOverBudget() ? 'danger' : ($r->usage_percent > 80 ? 'warning' : 'success')),
                TextColumn::make('created_by')->label('Ditetapkan oleh'),
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
            ->defaultSort('period_label', 'desc');
    }
}
