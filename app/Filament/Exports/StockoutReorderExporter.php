<?php

namespace App\Filament\Exports;

use App\Models\StockoutReorderCandidate;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class StockoutReorderExporter extends Exporter
{
    protected static ?string $model = StockoutReorderCandidate::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('InternalCode')->label('Kod Design'),
            ExportColumn::make('Description')->label('Jenis Item'),
            ExportColumn::make('category.Description')->label('Kategori'),
            ExportColumn::make('vendor.Description')->label('Supplier'),
            ExportColumn::make('sold_count')->label('Pernah Terjual'),
            ExportColumn::make('last_sale_date')->label('Jualan Terkini'),
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
