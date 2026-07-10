<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\StockoutReorderCandidate;
use Illuminate\Support\Facades\DB;

/**
 * Kira agregat StockoutReorder sekali di sini (dipanggil dari SyncJemisysMirrors selepas
 * InventoryPiece disegerak) & simpan hasil ke stockout_reorder_candidates - App\Filament\Pages\
 * StockoutReorder baca terus drpd jadual kecil ni, BUKAN agregat 481K baris setiap page
 * load/filter/sort/paginate (rujuk nota di StockoutReorder - realVendor() padan 91% baris,
 * jadi tiada index boleh percepatkan agregat live).
 */
class StockoutReorderMaterializer
{
    public static function materialize(): int
    {
        $rows = InventoryPiece::query()
            ->realVendor()
            ->select([
                'InternalCode',
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw('MAX(VendorCode) as VendorCode'),
                DB::raw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_count'),
                DB::raw('MAX(SalesDate) as last_sale_date'),
            ])
            ->groupBy('InternalCode')
            // havingRaw kena ulang expression penuh, bukan alias - SQL Server tak benarkan alias dlm HAVING.
            ->havingRaw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) >= 3 AND SUM(QtyOnHand) = 0')
            ->get()
            ->map(fn ($r) => [
                'InternalCode' => $r->InternalCode,
                'Description' => $r->Description,
                'CategoryCode' => $r->CategoryCode,
                'VendorCode' => $r->VendorCode,
                'sold_count' => (int) $r->sold_count,
                'last_sale_date' => $r->last_sale_date,
                'synced_at' => now(),
            ])
            ->all();

        StockoutReorderCandidate::truncate();

        // insert() satu batch gergasi boleh lebihi had placeholder statement disediakan MySQL
        // (1390 "too many placeholders") bila calon banyak - chunk 500 baris x 7 lajur = selamat.
        foreach (array_chunk($rows, 500) as $chunk) {
            StockoutReorderCandidate::insert($chunk);
        }

        return count($rows);
    }
}
