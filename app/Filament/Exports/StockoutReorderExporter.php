<?php

namespace App\Filament\Exports;

use App\Models\StockoutReorderCandidate;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;
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
            ExportColumn::make('vendor_codes')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state): string => str_replace(',', ', ', (string) $state)),
            // repair_qty_on_hand bukan lajur candidateQuery() lagi (rujuk nota
            // StockoutReorderCandidate - dikira berasingan per-rekod utk elak overhead pd
            // COUNT()/pagination). Kira semula di sini per-baris eksport, tanpa penapis
            // vendor/cawangan (job eksport dijalankan berasingan drpd konteks Livewire, tiada
            // akses terus pd filter UI semasa - papar jumlah penuh, sepadan snapshot eksport).
            ExportColumn::make('repair_qty_on_hand')
                ->label('Stok Repair')
                ->state(fn (Model $record): int => StockoutReorderCandidate::repairQtyOnHandFor($record->InternalCode)),
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
