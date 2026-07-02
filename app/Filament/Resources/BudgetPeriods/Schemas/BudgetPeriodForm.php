<?php

namespace App\Filament\Resources\BudgetPeriods\Schemas;

use App\Models\Jemisys\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class BudgetPeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextInput::make('period_label')
                            ->label('Bulan (YYYY-MM)')
                            ->placeholder(now()->format('Y-m'))
                            ->default(now()->format('Y-m'))
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('category_code')
                            ->label('Kategori (kosong = keseluruhan)')
                            ->options(fn () => Category::where('CategoryCode', '!=', '')
                                ->pluck('Description', 'CategoryCode'))
                            ->searchable()
                            ->disabledOn('edit'),
                        TextInput::make('budget_amount')
                            ->label('Jumlah Budget (RM)')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('created_by')
                            ->label('Ditetapkan oleh')
                            ->default(fn () => Auth::user()?->name)
                            ->required()
                            ->disabledOn('edit'),
                    ]),
            ]);
    }
}
