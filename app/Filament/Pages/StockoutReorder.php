<?php

namespace App\Filament\Pages;

use App\Filament\Exports\StockoutReorderExporter;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Vendor;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Design pernah laku (>=3 pcs terjual) tapi kini stok=0 di semua saluran (rujuk realVendor(),
 * TIADA physicalStore() - sepadan definisi asal widget StockoutProvenSellers) - calon reorder
 * segera. Dipindah keluar drpd dashboard (list terlalu panjang) ke page sendiri supaya staff
 * boleh filter ikut kategori/supplier/jenis item & eksport senarai lepas filter.
 *
 * Guna ->query() (bukan ->records()) sengaja - table berasaskan ->records() (cth. BranchFocus,
 * SupplierPerformance) TIDAK menyokong filter/eksport sebenar sebab Filament terus panggil
 * closure data source tanpa aplikasikan filter/sort/query eksport (rujuk
 * Filament\Tables\Concerns\HasRecords::getTableRecords() - cabang "! hasQuery()"). Dengan
 * query Eloquent sebenar, filter/sort/paginate/ExportAction semua guna mekanisme standard
 * Filament (sepadan InventoryPiecesTable).
 */
class StockoutReorder extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.stockout-reorder';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Reorder Segera';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory Health';

    protected static ?int $navigationSort = 3;

    public function getHeading(): string|Htmlable
    {
        return 'Best-seller Sold Out - Calon Reorder Segera';
    }

    public function getSubheading(): ?string
    {
        return 'Design pernah laku (>=3 pcs) tapi kini stok=0 di semua saluran - guna senarai ni utk reorder segera.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::baseQuery())
            ->columns([
                TextColumn::make('InternalCode')->label('Kod Design')->searchable()->sortable(),
                TextColumn::make('Description')->label('Jenis Item')->limit(30)->searchable()->sortable(),
                TextColumn::make('category.Description')->label('Kategori')->badge(),
                TextColumn::make('vendor.Description')->label('Supplier'),
                TextColumn::make('sold_count')->label('Pernah Terjual')->numeric()->sortable()->badge()->color('danger'),
                TextColumn::make('last_sale_date')->label('Jualan Terkini')->date('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('CategoryCode')
                    ->label('Kategori')
                    ->options(fn () => Cache::remember('stockout_reorder_category_options', 600, fn () => Category::where('CategoryCode', '!=', '')
                        ->orderBy('Description')
                        ->pluck('Description', 'CategoryCode'))),

                SelectFilter::make('VendorCode')
                    ->label('Supplier')
                    ->searchable()
                    ->options(fn () => Cache::remember('stockout_reorder_vendor_options', 600, fn () => Vendor::where('VendorCode', '!=', '.')
                        ->pluck('Description', 'VendorCode')
                        ->sort())),

                SelectFilter::make('Description')
                    ->label('Jenis Item')
                    ->searchable()
                    ->options(fn () => Cache::remember('stockout_reorder_item_options', 600, fn () => InventoryPiece::query()
                        ->realVendor()
                        ->whereNotNull('Description')
                        ->distinct()
                        ->orderBy('Description')
                        ->pluck('Description', 'Description'))),
            ])
            ->filtersFormColumns(3)
            ->toolbarActions([
                ExportAction::make()->exporter(StockoutReorderExporter::class),
            ])
            ->defaultSort('sold_count', 'desc')
            ->paginated([10, 25, 50])
            ->searchPlaceholder('Cari kod design atau jenis item...');
    }

    private static function baseQuery(): Builder
    {
        return InventoryPiece::query()
            ->realVendor()
            ->select([
                DB::raw('InternalCode as InventoryCode'),
                'InternalCode',
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw('MAX(VendorCode) as VendorCode'),
                DB::raw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold_count'),
                DB::raw('SUM(QtyOnHand) as stock_count'),
                DB::raw('MAX(SalesDate) as last_sale_date'),
            ])
            ->groupBy('InternalCode')
            // havingRaw kena ulang expression penuh, bukan alias - SQLite/MySQL benarkan HAVING
            // rujuk alias SELECT, tapi SQL Server tak (sama nota spt widget asal).
            ->havingRaw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) >= 3 AND SUM(QtyOnHand) = 0');
    }
}
