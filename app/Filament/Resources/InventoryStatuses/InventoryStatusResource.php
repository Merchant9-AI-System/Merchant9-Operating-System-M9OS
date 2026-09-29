<?php

namespace App\Filament\Resources\InventoryStatuses;

use App\Filament\Resources\InventoryStatuses\Pages\ListInventoryStatuses;
use App\Filament\Resources\InventoryStatuses\Schemas\InventoryStatusInfolist;
use App\Filament\Resources\InventoryStatuses\Tables\InventoryStatusesTable;
use App\Models\Jemisys\InventoryPiece;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Senarai SEMUA piece inventori (tiada scope onHand()) berserta Status JEMiSys mentah -
 * beza dgn InventoryPieceResource ("Stok Semasa") yg hanya papar stok on-hand. Read-only,
 * sama macam sepupu dia - tiada create/edit/delete di sini.
 */
class InventoryStatusResource extends Resource
{
    protected static ?string $model = InventoryPiece::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Status Inventori';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory Health';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'InternalCode';

    protected static ?string $modelLabel = 'Status Inventori';

    protected static ?string $pluralModelLabel = 'Status Inventori';

    public static function infolist(Schema $schema): Schema
    {
        return InventoryStatusInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryStatusesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryStatuses::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
