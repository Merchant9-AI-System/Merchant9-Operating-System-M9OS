<?php

namespace App\Filament\Resources\BudgetPeriods;

use App\Filament\Resources\BudgetPeriods\Pages\CreateBudgetPeriod;
use App\Filament\Resources\BudgetPeriods\Pages\EditBudgetPeriod;
use App\Filament\Resources\BudgetPeriods\Pages\ListBudgetPeriods;
use App\Filament\Resources\BudgetPeriods\Pages\ViewBudgetPeriod;
use App\Filament\Resources\BudgetPeriods\Schemas\BudgetPeriodForm;
use App\Filament\Resources\BudgetPeriods\Schemas\BudgetPeriodInfolist;
use App\Filament\Resources\BudgetPeriods\Tables\BudgetPeriodsTable;
use App\Models\BudgetPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BudgetPeriodResource extends Resource
{
    protected static ?string $model = BudgetPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Open-to-Buy Budget';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'period_label';

    public static function form(Schema $schema): Schema
    {
        return BudgetPeriodForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BudgetPeriodInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BudgetPeriodsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBudgetPeriods::route('/'),
            'create' => CreateBudgetPeriod::route('/create'),
            'view' => ViewBudgetPeriod::route('/{record}'),
            'edit' => EditBudgetPeriod::route('/{record}/edit'),
        ];
    }

    // Cuma manager boleh tetapkan/ubah budget - staff read-only.
}
