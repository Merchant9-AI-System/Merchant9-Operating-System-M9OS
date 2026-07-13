<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Support\RestockAnalysisCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Berat apa perlu restock (atau tidak), silang Kategori x Cawangan - 100% drpd data JEMiSys
 * sebenar (rujuk RestockAnalysisCalculator). TIADA pergantungan pada PO/GRN/data manual.
 */
class RestockByWeight extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.restock-by-weight';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Restock ikut Berat';

    protected static string|\UnitEnum|null $navigationGroup = 'Analisis JEMiSys';

    protected static ?int $navigationSort = 2;

    public function getSubheading(): ?string
    {
        return 'Cadangan restock ikut Berat Emas, silang Kategori x Cawangan - dikira 100% drpd data JEMiSys sebenar.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage, ?array $filters, ?string $search, ?string $sortColumn, ?string $sortDirection) {
                // ->records() TIDAK auto-paginate/filter/search/sort spt ->query() - Filament
                // hantar SEMUA parameter ni terus ke closure, closure WAJIB uruskan semuanya
                // sendiri (rujuk Filament\Tables\Concerns\HasRecords::getTableRecords()).
                $all = RestockAnalysisCalculator::byWeight()
                    ->map(fn ($r, $i) => $r + ['InventoryCode' => 'rbw_'.$i]);

                if ($categoryCode = $filters['category_code']['value'] ?? null) {
                    $all = $all->where('category_code', $categoryCode);
                }

                if ($storeCode = $filters['store_code']['value'] ?? null) {
                    $all = $all->where('store_code', $storeCode);
                }

                if ($verdict = $filters['verdict']['value'] ?? null) {
                    $all = $all->where('verdict', $verdict);
                }

                if (filled($search)) {
                    $needle = mb_strtolower($search);
                    $all = $all->filter(fn ($r) => str_contains(mb_strtolower((string) $r['category_name']), $needle));
                }

                if (filled($sortColumn)) {
                    $all = $sortDirection === 'desc'
                        ? $all->sortByDesc($sortColumn)
                        : $all->sortBy($sortColumn);
                }

                $all = $all->values();

                $page = (int) $page;
                $recordsPerPage = (int) $recordsPerPage;

                return new LengthAwarePaginator(
                    InventoryPiece::hydrate($all->forPage($page, $recordsPerPage)->values()->all()),
                    $all->count(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('category_name')->label('Kategori')->searchable()->sortable(),
                TextColumn::make('store_code')->label('Cawangan')->badge()->sortable(),
                TextColumn::make('bucket')->label('Berat')->sortable(),
                TextColumn::make('current_stock')->label('Stok Semasa')->numeric()->sortable(),
                TextColumn::make('target_stock')->label('Stok Optimum')->numeric()->sortable(),
                TextColumn::make('gap')->label('Gap')->numeric()->sortable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : ($state < 0 ? 'warning' : 'success')),
                TextColumn::make('velocity_per_month')->label('Jualan/Bulan')->numeric(2),
                TextColumn::make('verdict')->label('Cadangan')->badge()
                    ->color(fn ($state) => match ($state) {
                        RestockAnalysisCalculator::VERDICT_SOLD_OUT => 'danger',
                        RestockAnalysisCalculator::VERDICT_RESTOCK => 'warning',
                        RestockAnalysisCalculator::VERDICT_OVERSTOCK => 'info',
                        RestockAnalysisCalculator::VERDICT_OK => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('category_code')->label('Kategori')
                    ->options(fn () => Category::where('CategoryCode', '!=', '')->pluck('Description', 'CategoryCode')),
                SelectFilter::make('store_code')->label('Cawangan')
                    ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode')),
                SelectFilter::make('verdict')->label('Cadangan')->options([
                    RestockAnalysisCalculator::VERDICT_SOLD_OUT => RestockAnalysisCalculator::VERDICT_SOLD_OUT,
                    RestockAnalysisCalculator::VERDICT_RESTOCK => RestockAnalysisCalculator::VERDICT_RESTOCK,
                    RestockAnalysisCalculator::VERDICT_OK => RestockAnalysisCalculator::VERDICT_OK,
                    RestockAnalysisCalculator::VERDICT_OVERSTOCK => RestockAnalysisCalculator::VERDICT_OVERSTOCK,
                    RestockAnalysisCalculator::VERDICT_NO_DATA => RestockAnalysisCalculator::VERDICT_NO_DATA,
                ]),
            ])
            ->defaultSort('gap', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
