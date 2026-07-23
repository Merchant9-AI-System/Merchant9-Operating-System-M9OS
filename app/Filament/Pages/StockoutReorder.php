<?php

namespace App\Filament\Pages;

use App\Filament\Exports\StockoutReorderExporter;
use App\Filament\Widgets\BestSellerLostOpportunityStats;
use App\Filament\Widgets\BestSellerLostOpportunityTable;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use App\Models\StockoutReorderCandidate;
use App\Support\ProductImageFetcher;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
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
 * Baca drpd stockout_reorder_candidates (snapshot pra-agregat per (InternalCode, VendorCode,
 * StoreCode), rujuk App\Support\StockoutReorderMaterializer) - BUKAN agregat live 481K baris
 * jemisys_inventory_mirror setiap page load/filter/sort/paginate (realVendor() padan 91% baris,
 * jadi tiada index boleh percepatkan agregat tsb, disahkan via EXPLAIN ~50 saat setiap panggilan).
 *
 * Filter Supplier/Exclude Supplier & Exclude Cawangan INTERAKTIF - StockoutReorderCandidate::
 * candidateQuery() kira SEMULA sold_count & kelayakan "stok=0" secara live drpd jadual kecil ni
 * (~131.8K baris, jauh lebih kecil drpd 481K asal, jadi GROUP BY/HAVING live tetap pantas) ikut
 * vendor/cawangan dipilih/dikecualikan - BUKAN sekadar tapis baris drpd senarai statik (rujuk
 * sejarah: exclude 1 vendor minor pernah sembunyikan seluruh design walaupun vendor lain [cth.
 * GRJ] masih bekalkan majoriti piece - fixed dgn re-grain jadual ni drpd satu-baris-setiap-
 * design kpd satu-baris-setiap-vendor, kemudian satu-baris-setiap-cawangan).
 *
 * "Stok Repair"/"Sold By Branch" turut ikut serta bila supplier/cawangan di-exclude/include
 * (exclude cawangan bermaksud "pretend cawangan tu tak wujud" merentasi SEMUA angka pd baris,
 * bukan sebahagian sahaja) - TAPI dikira BERASINGAN per-rekod yg dipaparkan (rujuk
 * StockoutReorderCandidate::repairQtyOnHandFor()/soldByBranchFor()), BUKAN dimasukkan terus dlm
 * candidateQuery(). Percubaan awal (subquery berkorelasi/leftJoinSub dlm candidateQuery()
 * sendiri) buat COUNT()/pagination ambil 7-10+ saat (kira utk SEMUA ~27K design walhal cuma
 * 10-50 dipaparkan setiap page) & leftJoinSub turut pecahkan carian/susun lalai Filament pd
 * lajur InternalCode (wujud dlm >1 jadual serentak selepas join - "ambiguous column").
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
                ImageColumn::make('InternalCodeImage')
                    ->label('Imej')
                    ->state(fn (StockoutReorderCandidate $record) => ProductImageFetcher::firstImageUrlFor($record->InternalCode))
                    ->imageHeight(50)
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->url(fn (?string $state): ?string => $state)
                    ->openUrlInNewTab()
                    ->placeholder('No image'),
                TextColumn::make('InternalCode')->label('Kod Design')->searchable()->sortable(),
                TextColumn::make('sold_count')->label('Pernah Terjual')->numeric()->sortable()->badge()->color('danger'),
                TextColumn::make('Description')->label('Jenis Item')->limit(30)->searchable()->sortable(),
                TextColumn::make('category.Description')->label('Kategori')->badge(),
                TextColumn::make('repair_qty_on_hand')
                    ->label('Stok Repair')
                    ->state(function (StockoutReorderCandidate $record): ?string {
                        $excludedStoreCodes = $this->getTableFilterState('StoreCodeExclude')['values'] ?? [];
                        $qty = StockoutReorderCandidate::repairQtyOnHandFor($record->InternalCode, excludedStoreCodes: $excludedStoreCodes);

                        return $qty > 0 ? "{$qty} pcs in stock" : null;
                    })
                    ->badge()
                    ->color('info')
                    ->placeholder('-'),
                TextColumn::make('last_sale_date')->label('Jualan Terkini')->date('d/m/Y')->sortable(),
                TextColumn::make('vendor_codes')
                    ->label('Supplier')
                    ->state(fn (StockoutReorderCandidate $record): array => $record->vendorCodes())
                    ->limitList(3)
                    ->badge(),
                TextColumn::make('sold_by_branch')
                    ->label('Sold By Branch')
                    ->state(function (StockoutReorderCandidate $record): array {
                        $includedVendorCodes = $this->getTableFilterState('VendorCode')['values'] ?? [];
                        $excludedVendorCodes = $this->getTableFilterState('VendorCodeExclude')['values'] ?? [];
                        $excludedStoreCodes = $this->getTableFilterState('StoreCodeExclude')['values'] ?? [];

                        return StockoutReorderCandidate::soldByBranchFor(
                            $record->InternalCode,
                            $includedVendorCodes,
                            $excludedVendorCodes,
                            excludedStoreCodes: $excludedStoreCodes,
                        );
                    })
                    ->wrap()
                    ->color('secondary')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('CategoryCode')
                    ->label('Kategori')
                    ->native()
                    ->searchable('CategoryCode')
                    ->options(fn () => Cache::remember('stockout_reorder_category_options', 600, fn () => Category::where('CategoryCode', '!=', '')
                        ->orderBy('Description')
                        ->pluck('Description', 'CategoryCode')
                        ->toArray())),

                SelectFilter::make('VendorCode')
                    ->label('Supplier')
                    ->native()
                    ->multiple()
                    ->searchable('VendorCode')
                    ->options(fn () => self::vendorOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? []),
                        fn (Builder $q) => $q->whereIn('VendorCode', $data['values']),
                    )),

                SelectFilter::make('VendorCodeExclude')
                    ->label('Exclude Supplier')
                    ->native()
                    ->multiple()
                    ->searchable('VendorCode')
                    ->options(fn () => self::vendorOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? []),
                        fn (Builder $q) => $q->whereNotIn('VendorCode', $data['values']),
                    )),

                SelectFilter::make('StoreCodeExclude')
                    ->label('Exclude Cawangan')
                    ->native()
                    ->multiple()
                    ->searchable('StoreCode')
                    ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode'))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? []),
                        fn (Builder $q) => $q->whereNotIn('StoreCode', $data['values']),
                    )),

                // SelectFilter::make('Description')
                //     ->label('Jenis Item')
                //     ->searchable()
                //     ->options(fn () => Cache::remember('stockout_reorder_item_options', 600, fn () => InventoryPiece::query()
                //         ->realVendor()
                //         ->whereNotNull('Description')
                //         ->distinct()
                //         ->orderBy('Description')
                //         ->pluck('Description', 'Description'))),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->toolbarActions([
                ExportAction::make()->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->exporter(StockoutReorderExporter::class),
            ])
            ->defaultSort('sold_count', 'desc')
            ->paginated([10, 25, 50])
            ->searchPlaceholder('Cari kod design atau jenis item...');
    }

    private static function baseQuery(): Builder
    {
        return StockoutReorderCandidate::candidateQuery();
    }

    /**
     * @return array<string, string>
     */
    private static function vendorOptions(): array
    {
        return Cache::remember('stockout_reorder_vendor_options', 600, fn () => Vendor::where('VendorCode', '!=', '.')
            ->get()
            ->mapWithKeys(fn (Vendor $v) => [trim($v->VendorCode) => trim($v->VendorCode).' - '.$v->Description])
            ->sort()
            ->toArray());
    }
}
