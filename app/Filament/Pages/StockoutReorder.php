<?php

namespace App\Filament\Pages;

use App\Filament\Exports\StockoutReorderExporter;
use App\Filament\Widgets\BestSellerLostOpportunityStats;
use App\Filament\Widgets\BestSellerLostOpportunityTable;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\Vendor;
use App\Models\StockoutReorderCandidate;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
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

/**
 * Design pernah laku (>=3 pcs terjual) tapi kini stok=0 di semua saluran (rujuk realVendor(),
 * TIADA physicalStore() - sepadan definisi asal widget StockoutProvenSellers) - calon reorder
 * segera. Dipindah keluar drpd dashboard (list terlalu panjang) ke page sendiri supaya staff
 * boleh filter ikut kategori/supplier/jenis item & eksport senarai lepas filter.
 *
 * Baca terus drpd stockout_reorder_candidates (snapshot pra-agregat, rujuk
 * App\Support\StockoutReorderMaterializer) - BUKAN agregat live 481K baris jemisys_inventory_mirror
 * setiap page load/filter/sort/paginate. Page ni SATU-SATUNYA di seluruh app yg tiada caching
 * langsung sebelum ni sebab guna ->query() (perlu utk filter/sort/eksport Filament standard,
 * rujuk Filament\Tables\Concerns\HasRecords::getTableRecords() cabang "! hasQuery()") - realVendor()
 * padan 91% baris jemisys_inventory_mirror, jadi tiada index boleh percepatkan agregat tsb
 * (disahkan via EXPLAIN, ~50 saat setiap panggilan). Materialize ke jadual kecil menyelesaikan
 * ni tanpa perlu ubah apa-apa mekanisme filter/sort/eksport sedia ada - hanya sumber data ditukar.
 */
class StockoutReorder extends Page implements HasTable
{
    use HasPageShield;
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

    /**
     * CEO Dashboard Phase 1 (D) "Best Seller Lost Opportunity" - header widget SAHAJA
     * ditambah di sini, table/filter/export sedia ada di bawah TIDAK diubah. Boleh dimatikan
     * via .env CEO_LOST_OPPORTUNITY_ENABLED=false (config/dashboard.php, widget canView()).
     */
    protected function getHeaderWidgets(): array
    {
        return [
            BestSellerLostOpportunityStats::class,
            BestSellerLostOpportunityTable::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::baseQuery())
            ->columns([
                TextColumn::make('InternalCode')->label('Kod Design')->searchable()->sortable(),
                TextColumn::make('Description')->label('Jenis Item')->limit(30)->searchable()->sortable(),
                TextColumn::make('category.Description')->label('Kategori')->badge(),
                TextColumn::make('vendor.Description')->label('Supplier')->searchable(),
                TextColumn::make('sold_count')->label('Pernah Terjual')->numeric()->sortable()->badge()->color('danger'),
                TextColumn::make('last_sale_date')->label('Jualan Terkini')->date('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('CategoryCode')
                    ->label('Kategori')
                    // ->toArray() sengaja - elak isu unserialize __PHP_Incomplete_Class bila
                    // cache Collection ditulis dari konteks CLI & dibaca dari konteks web
                    // (php artisan serve) atau sebaliknya (sama nota spt RearrangeCalculator).
                    ->options(fn () => Cache::remember('stockout_reorder_category_options', 600, fn () => Category::where('CategoryCode', '!=', '')
                        ->orderBy('Description')
                        ->pluck('Description', 'CategoryCode')
                        ->toArray())),

                SelectFilter::make('VendorCode')
                    ->label('Supplier')
                    ->searchable()
                    ->options(fn () => Cache::remember('stockout_reorder_vendor_options', 600, fn () => Vendor::where('VendorCode', '!=', '.')
                        ->pluck('Description', 'VendorCode')
                        ->sort()
                        ->toArray())),

                // SelectFilter::make('Description')
                //     ->label('Jenis Item')
                //     ->searchable()
                //     ->options(fn () => Cache::remember('stockout_reorder_item_options', 600, fn () => InventoryPiece::query()
                //         ->realVendor()
                //         ->whereNotNull('Description')
                //         ->distinct()
                //         ->orderBy('Description')
                //         ->pluck('Description', 'Description'))),
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
        return StockoutReorderCandidate::query();
    }
}
