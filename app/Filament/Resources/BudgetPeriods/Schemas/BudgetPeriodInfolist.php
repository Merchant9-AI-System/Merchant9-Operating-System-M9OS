<?php

namespace App\Filament\Resources\BudgetPeriods\Schemas;

use App\Models\BudgetPeriod;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BudgetPeriodInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Open-to-Buy Budget')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('period_label')->label('Bulan'),
                                TextEntry::make('category.Description')->label('Kategori')->placeholder('Keseluruhan'),
                                TextEntry::make('budget_amount')->label('Budget')->money('MYR'),
                                TextEntry::make('created_by')->label('Ditetapkan oleh'),
                                TextEntry::make('spent_amount')->label('Spent')->state(fn (BudgetPeriod $r) => $r->spent_amount)->money('MYR'),
                                TextEntry::make('remaining_amount')->label('Baki')->state(fn (BudgetPeriod $r) => $r->remaining_amount)->money('MYR'),
                                TextEntry::make('usage_percent')->label('% Guna')
                                    ->state(fn (BudgetPeriod $r) => round($r->usage_percent, 1).'%')
                                    ->badge()
                                    ->color(fn (BudgetPeriod $r) => $r->isOverBudget() ? 'danger' : ($r->usage_percent > 80 ? 'warning' : 'success')),
                            ]),
                    ]),
            ]);
    }
}
