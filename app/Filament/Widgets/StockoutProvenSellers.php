<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\InventoryPiece;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Design pernah laku (>=3 pcs terjual) tapi kini stok=0 di semua cawangan fizikal -
 * calon reorder segera (rujuk PHASE1_FILAMENT_PLAN.md §4 W4).
 *
 * Query GROUP BY/HAVING ni scan semua baris TblInventory (mahal) - sepadan corak caching
 * widget lain (InventoryKpiStats/CapitalAgingChart/GoldVsIdealByBranch guna Cache::remember
 * 10 minit), supaya query berat ni jalan sekali setiap 10 minit sahaja, bukan setiap kali
 * dashboard dimuat. `retry()` tambah daya tahan sekiranya SQLite kena lock sekejap (Windows).
 */
class StockoutProvenSellers extends TableWidget
{
    protected static ?string $heading = 'Best-seller Sold Out - Calon Reorder Segera';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->getCachedRows())
            ->columns([
                TextColumn::make('InternalCode')->label('Kod Design')->searchable(),
                TextColumn::make('Description')->label('Jenis Item')->limit(30),
                TextColumn::make('category_name')->label('Kategori')->badge(),
                TextColumn::make('vendor_name')->label('Supplier'),
                TextColumn::make('sold_count')->label('Pernah Terjual')->sortable()->badge()->color('danger'),
                TextColumn::make('last_sale_date')->label('Jualan Terkini')->date('d/m/Y')->sortable(),
            ])
            ->paginated([10, 25, 50])
            ->defaultSort('sold_count', 'desc');
    }

    protected function getCachedRows()
    {
        $rows = Cache::remember('stockout_proven_sellers', 3600, function () {
            // 6 percubaan, 800ms antara - toleransi ~4s utk lock sementara (cth. antivirus scan
            // selepas jemisys.db ditulis semula). Query ni disahkan cepat (~370ms) bila tiada lock.
            return retry(6, function () {
                // TIADA physicalStore() - sepadan definisi asal, merentas semua saluran (fizikal + web).
                return InventoryPiece::query()
                    ->realVendor()
                    ->select([
                        DB::raw('InternalCode as InventoryCode'), // unik ikut kumpulan design
                        'InternalCode',
                        DB::raw('MAX(Description) as Description'),
                        DB::raw('MAX(CategoryCode) as CategoryCode'),
                        DB::raw('MAX(VendorCode) as VendorCode'),
                        DB::raw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_count'),
                        DB::raw('SUM(QtyOnHand) as stock_count'),
                        DB::raw('MAX(SalesDate) as last_sale_date'),
                    ])
                    ->groupBy('InternalCode')
                    ->havingRaw('sold_count >= 3 AND stock_count = 0')
                    ->orderByDesc('sold_count')
                    ->get()
                    ->map(fn ($r) => [
                        'InventoryCode' => $r->InventoryCode,
                        'InternalCode' => $r->InternalCode,
                        'Description' => $r->Description,
                        'category_name' => optional($r->category)->Description ?? $r->CategoryCode,
                        'vendor_name' => optional($r->vendor)->Description ?? $r->VendorCode,
                        'sold_count' => (int) $r->sold_count,
                        'last_sale_date' => $r->last_sale_date,
                    ])
                    ->all();
            }, 800);
        });

        return InventoryPiece::hydrate($rows);
    }
}
