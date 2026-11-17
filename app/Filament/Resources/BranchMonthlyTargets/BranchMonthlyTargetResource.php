<?php

namespace App\Filament\Resources\BranchMonthlyTargets;

use App\Filament\Resources\BranchMonthlyTargets\Pages\CreateBranchMonthlyTarget;
use App\Filament\Resources\BranchMonthlyTargets\Pages\EditBranchMonthlyTarget;
use App\Filament\Resources\BranchMonthlyTargets\Pages\ListBranchMonthlyTargets;
use App\Filament\Resources\BranchMonthlyTargets\RelationManagers\RevisionsRelationManager;
use App\Filament\Resources\BranchMonthlyTargets\Schemas\BranchMonthlyTargetForm;
use App\Filament\Resources\BranchMonthlyTargets\Tables\BranchMonthlyTargetsTable;
use App\Models\BranchMonthlyTarget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Sasaran jualan bulanan setiap cawangan (RM & gram) - senarai ikut bulan dan cawangan utk HQ (M07). Kebenaran
 * Shield (BranchMonthlyTargetPolicy). Ketua cawangan menetapkan/mengemas kini sasaran sendiri di /home; semua
 * perubahan (kedua-dua laluan) direkod dlm sejarah semakan (BranchMonthlyTarget::record()).
 */
class BranchMonthlyTargetResource extends Resource
{
    protected static ?string $model = BranchMonthlyTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Sasaran Bulanan Cawangan';

    protected static string|\UnitEnum|null $navigationGroup = 'Branch Performance';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Sasaran Bulanan';

    protected static ?string $pluralModelLabel = 'Sasaran Bulanan';

    public static function form(Schema $schema): Schema
    {
        return BranchMonthlyTargetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BranchMonthlyTargetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RevisionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranchMonthlyTargets::route('/'),
            'create' => CreateBranchMonthlyTarget::route('/create'),
            'edit' => EditBranchMonthlyTarget::route('/{record}/edit'),
        ];
    }
}
