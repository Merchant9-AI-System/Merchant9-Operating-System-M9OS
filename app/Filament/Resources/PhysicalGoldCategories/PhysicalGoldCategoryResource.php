<?php

namespace App\Filament\Resources\PhysicalGoldCategories;

use App\Filament\Resources\PhysicalGoldCategories\Pages\CreatePhysicalGoldCategory;
use App\Filament\Resources\PhysicalGoldCategories\Pages\EditPhysicalGoldCategory;
use App\Filament\Resources\PhysicalGoldCategories\Pages\ListPhysicalGoldCategories;
use App\Filament\Resources\PhysicalGoldCategories\Pages\ViewPhysicalGoldCategory;
use App\Filament\Resources\PhysicalGoldCategories\Schemas\PhysicalGoldCategoryForm;
use App\Filament\Resources\PhysicalGoldCategories\Schemas\PhysicalGoldCategoryInfolist;
use App\Filament\Resources\PhysicalGoldCategories\Tables\PhysicalGoldCategoriesTable;
use App\Models\PhysicalGoldCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PhysicalGoldCategoryResource extends Resource
{
    protected static ?string $model = PhysicalGoldCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Physical Gold Categories';

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PhysicalGoldCategoryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PhysicalGoldCategoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhysicalGoldCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhysicalGoldCategories::route('/'),
            'create' => CreatePhysicalGoldCategory::route('/create'),
            'view' => ViewPhysicalGoldCategory::route('/{record}'),
            'edit' => EditPhysicalGoldCategory::route('/{record}/edit'),
        ];
    }
}
