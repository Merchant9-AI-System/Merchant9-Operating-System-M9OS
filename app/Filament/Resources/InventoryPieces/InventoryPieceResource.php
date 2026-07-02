<?php

namespace App\Filament\Resources\InventoryPieces;

use App\Filament\Resources\InventoryPieces\Pages\ListInventoryPieces;
use App\Filament\Resources\InventoryPieces\Pages\ViewInventoryPiece;
use App\Filament\Resources\InventoryPieces\Schemas\InventoryPieceInfolist;
use App\Filament\Resources\InventoryPieces\Tables\InventoryPiecesTable;
use App\Models\Jemisys\InventoryPiece;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only: data ini datang terus daripada JEMiSys (TblInventory), diurus di sistem POS.
 * Tiada create/edit/delete di sini - Order Recommendation & rearrange (Phase 2/3) yang
 * ada tindakan tulis (procurement_orders), bukan raw inventory ini.
 */
class InventoryPieceResource extends Resource
{
    protected static ?string $model = InventoryPiece::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Stok Semasa';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory Health';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'InternalCode';

    public static function infolist(Schema $schema): Schema
    {
        return InventoryPieceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryPiecesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryPieces::route('/'),
            'view' => ViewInventoryPiece::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Default: papar stok semasa sahaja dengan vendor sah (sepadan procurement_report.py).
        return parent::getEloquentQuery()->onHand()->realVendor();
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
