<?php

namespace App\Filament\Resources\ExpenseClaims;

use App\Filament\Resources\ExpenseClaims\Pages\ListExpenseClaims;
use App\Filament\Resources\ExpenseClaims\Pages\ViewExpenseClaim;
use App\Filament\Resources\ExpenseClaims\RelationManagers\LinesRelationManager;
use App\Filament\Resources\ExpenseClaims\Schemas\ExpenseClaimInfolist;
use App\Filament\Resources\ExpenseClaims\Tables\ExpenseClaimsTable;
use App\Models\ExpenseClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Permukaan approver SAHAJA (role head_finance/super_admin) - staf Finance cipta/urus claim
 * BAGI PIHAK claimant (cth. CEO) via halaman Inertia berasingan
 * (resources/js/Pages/ExpenseClaims/Index.vue + Edit.vue + ExpenseClaimController), ikut corak
 * split BranchDemand (Inertia=hantaran, Filament=semakan/kelulusan). TIADA Create page di sini
 * sengaja - claim baharu HANYA dicipta drpd permukaan Inertia staf Finance.
 */
class ExpenseClaimResource extends Resource
{
    protected static ?string $model = ExpenseClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Expense Claims';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'claim_number';

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseClaimInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseClaims::route('/'),
            'view' => ViewExpenseClaim::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }
}
