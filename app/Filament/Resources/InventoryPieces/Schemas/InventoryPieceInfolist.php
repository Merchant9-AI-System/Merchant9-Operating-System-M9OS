<?php

namespace App\Filament\Resources\InventoryPieces\Schemas;

use App\Models\Jemisys\InventoryPiece;
use App\Support\ProductImageFetcher;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class InventoryPieceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        ImageEntry::make('InternalCodeImage')->label('Imej')->state(fn (InventoryPiece $record) => ProductImageFetcher::firstImageUrlFor($record->InternalCode))->extraImgAttributes(['loading' => 'lazy']),
                        TextEntry::make('InternalCode')->label('Kod Design'),
                        TextEntry::make('Description')->label('Jenis Item'),
                        TextEntry::make('category.Description')->label('Kategori'),
                        TextEntry::make('vendor.Description')->label('Supplier'),
                        TextEntry::make('StoreCode')->label('Cawangan'),
                        TextEntry::make('ClassCode')->label('Purity'),
                        TextEntry::make('JewelSize')->label('Saiz'),
                        TextEntry::make('GoldWeight')->label('Berat Emas (g)')->numeric(2),
                        TextEntry::make('TotalCost')->label('Kos')->money('MYR'),
                        TextEntry::make('PurchDate')->label('Tarikh Beli')->date('d/m/Y'),
                        TextEntry::make('ReceivedDate')->label('Tarikh Terima')->date('d/m/Y'),
                        TextEntry::make('age_days')->label('Umur (hari)'),
                    ]),
            ])->columns(1);
    }
}
