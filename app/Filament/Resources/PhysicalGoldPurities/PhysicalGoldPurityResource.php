<?php

namespace App\Filament\Resources\PhysicalGoldPurities;

use App\Filament\Resources\PhysicalGoldPurities\Pages\CreatePhysicalGoldPurity;
use App\Filament\Resources\PhysicalGoldPurities\Pages\EditPhysicalGoldPurity;
use App\Filament\Resources\PhysicalGoldPurities\Pages\ListPhysicalGoldPurities;
use App\Filament\Resources\PhysicalGoldPurities\Pages\ViewPhysicalGoldPurity;
use App\Filament\Resources\PhysicalGoldPurities\Schemas\PhysicalGoldPurityForm;
use App\Filament\Resources\PhysicalGoldPurities\Schemas\PhysicalGoldPurityInfolist;
use App\Filament\Resources\PhysicalGoldPurities\Tables\PhysicalGoldPuritiesTable;
use App\Models\PhysicalGoldPurity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PhysicalGoldPurityResource extends Resource
{
    protected static ?string $model = PhysicalGoldPurity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Purity Master';

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return PhysicalGoldPurityForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PhysicalGoldPurityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhysicalGoldPuritiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhysicalGoldPurities::route('/'),
            'create' => CreatePhysicalGoldPurity::route('/create'),
            'view' => ViewPhysicalGoldPurity::route('/{record}'),
            'edit' => EditPhysicalGoldPurity::route('/{record}/edit'),
        ];
    }
}
