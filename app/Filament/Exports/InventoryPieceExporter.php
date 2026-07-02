<?php

namespace App\Filament\Exports;

use App\Models\Jemisys\InventoryPiece;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class InventoryPieceExporter extends Exporter
{
    protected static ?string $model = InventoryPiece::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('InternalCode')->label('Kod Design'),
            ExportColumn::make('Description')->label('Jenis Item'),
            ExportColumn::make('category.Description')->label('Kategori'),
            ExportColumn::make('vendor.Description')->label('Supplier'),
            ExportColumn::make('StoreCode')->label('Cawangan'),
            ExportColumn::make('ClassCode')->label('Purity'),
            ExportColumn::make('JewelSize')->label('Saiz'),
            ExportColumn::make('GoldWeight')->label('Berat Emas (g)'),
            ExportColumn::make('TotalCost')->label('Kos (RM)'),
            ExportColumn::make('PurchDate')->label('Tarikh Beli'),
            ExportColumn::make('age_days')->label('Umur (hari)'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export selesai - '.Number::format($export->successful_rows).' '.str('baris')->plural($export->successful_rows).' dieksport.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal.';
        }

        return $body;
    }
}
