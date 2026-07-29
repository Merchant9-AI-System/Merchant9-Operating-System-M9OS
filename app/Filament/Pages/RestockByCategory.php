<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RestockByCategoryStats;
use App\Jobs\SyncJemisysMirrors;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Support\ProductImageFetcher;
use App\Support\RestockAnalysisCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cadangan restock per DESIGN (InternalCode) silang satu Kategori terpilih - rujuk
 * App\Support\RestockAnalysisCalculator::byCategory(). Jadual kosong SEHINGGA pengguna pilih
 * Kategori (arahan eksplisit: "hanya keluar data based on carian dan carian filter"), berbeza
 * drpd RestockBySize/RestockByWeight yg papar semua data secara lalai.
 */
class RestockByCategory extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable {
        InteractsWithTable::handleTableFilterUpdates as baseHandleTableFilterUpdates;
        InteractsWithTable::updatedTableSearch as baseUpdatedTableSearch;
    }

    protected string $view = 'filament.pages.restock-by-category';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Restock ikut Kategori';

    protected static string|\UnitEnum|null $navigationGroup = 'Analisis JEMiSys';

    protected static ?int $navigationSort = 3;

    /** Bilangan baris sejarah maksimum dipaparkan dlm modal "Lihat Sejarah". */
    private const HISTORY_MODAL_LIMIT = 30;

    public function getSubheading(): ?string
    {
        $base = 'Cadangan restock per design silang SATU Kategori terpilih - pilih Kategori utk papar data.';

        if (Cache::has(SyncJemisysMirrors::CACHE_KEY_SYNCING)) {
            return $base.' ⚠️ Data JEMiSys sedang disegerakkan sekarang - angka mungkin tidak lengkap sementara sync berjalan.';
        }

        return $base;
    }

    /** @see https://filamentphp.com/docs/5.x/navigation/custom-pages#adding-widgets-to-pages */
    protected function getHeaderWidgets(): array
    {
        return [
            RestockByCategoryStats::class,
        ];
    }

    /** Isi $stats pd RestockByCategoryStats - widget itu sendiri TIDAK buat query, papar sahaja. */
    public function getWidgetData(): array
    {
        return [
            'stats' => $this->getFilteredStats(),
        ];
    }

    /**
     * getWidgetData() cuma isi $stats SEKALI semasa mount pertama widget (rujuk komponen
     * Livewire berasingan - widget TIDAK remount bila jadual/penapis sahaja berubah, jadi
     * $stats "statik" kekal nilai lama walau kategori/carian ditukar). WAJIB dispatch event
     * secara eksplisit setiap kali penapis/carian betul2 berubah, widget dengar via #[On(...)]
     * (rujuk RestockByCategoryStats::refreshStats()).
     */
    protected function handleTableFilterUpdates(): void
    {
        $this->baseHandleTableFilterUpdates();
        $this->dispatchStatsRefresh();
    }

    public function updatedTableSearch(): void
    {
        $this->baseUpdatedTableSearch();
        $this->dispatchStatsRefresh();
    }

    protected function dispatchStatsRefresh(): void
    {
        $this->dispatch('restock-by-category-stats-updated', stats: $this->getFilteredStats());
    }

    /** Dikongsi antara table()->records() & getFilteredStats() - satu logik, dua pengguna. */
    protected function filteredDesigns(?array $filters, ?string $search): Collection
    {
        $categoryCode = $filters['category_code']['value'] ?? null;

        if (blank($categoryCode)) {
            return collect();
        }

        $all = RestockAnalysisCalculator::byCategory($categoryCode)
            ->map(fn ($r) => $r + ['InventoryCode' => trim($r['internal_code'])]);

        if ($storeCode = $filters['store_code']['value'] ?? null) {
            $all = $all->where('urgent_branch', $storeCode);
        }

        if ($verdict = $filters['verdict']['value'] ?? null) {
            $all = $all->where('verdict', $verdict);
        }

        if (filled($search)) {
            $needle = mb_strtolower($search);
            $all = $all->filter(fn ($r) => str_contains(mb_strtolower((string) $r['internal_code']), $needle)
                || str_contains(mb_strtolower((string) $r['description']), $needle));
        }

        return $all->values();
    }

    /** Statistik drpd set data TERKINI (kategori + filter + carian semasa) - bukan widget berasingan. */
    public function getFilteredStats(): array
    {
        $all = $this->filteredDesigns($this->tableFilters, $this->tableSearch);

        return [
            'has_category' => filled($this->tableFilters['category_code']['value'] ?? null),
            'total' => $all->count(),
            'needs_restock' => $all->whereIn('verdict', [
                RestockAnalysisCalculator::VERDICT_RESTOCK,
                RestockAnalysisCalculator::VERDICT_SOLD_OUT,
            ])->count(),
            'total_gap' => (int) $all->filter(fn ($r) => $r['gap'] > 0)->sum('gap'),
            'branches_affected' => $all->pluck('urgent_branch')->filter()->unique()->count(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage, ?array $filters, ?string $search, ?string $sortColumn, ?string $sortDirection) {
                $all = $this->filteredDesigns($filters, $search);

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
                ImageColumn::make('image')
                    ->label('Imej')
                    ->state(fn ($record) => ProductImageFetcher::firstImageUrlFor($record->internal_code))
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->url(fn ($record) => ProductImageFetcher::firstImageUrlFor($record->internal_code))
                    ->openUrlInNewTab()
                    ->placeholder('No Image')
                    ->imageHeight(50),
                TextColumn::make('internal_code')->label('Kod Design')->searchable()->sortable()->weight('bold'),
                TextColumn::make('description')->label('Jenis Item')->searchable()->toggleable(),
                TextColumn::make('size')->label('Saiz')->sortable(),
                TextColumn::make('weight')->label('Berat (g)')->numeric(2)->sortable(),
                TextColumn::make('current_stock')->label('Stok Semasa')->sortable()
                    ->state(fn ($record) => collect($record->stock_by_branch ?? [])
                        ->map(fn ($qty, $branch) => "{$branch}: {$qty}")
                        ->values()
                        ->all())
                    ->badge()
                    ->wrap()
                    ->color(fn (string $state) => str_ends_with($state, ': 0') ? 'danger' : 'success')
                    ->tooltip(fn ($record) => "Jumlah kesemua cawangan: {$record->current_stock}"),
                TextColumn::make('gap')->label('Gap')->numeric()->sortable()
                    ->tooltip('Stok Disyorkan - Stok Semasa. Positif = kurang stok (perlu restock).')
                    ->color(fn ($state) => $state > 0 ? 'danger' : ($state < 0 ? 'warning' : 'success')),
                TextColumn::make('verdict')->label('Cadangan')->badge()
                    ->color(fn ($state) => match ($state) {
                        RestockAnalysisCalculator::VERDICT_SOLD_OUT => 'danger',
                        RestockAnalysisCalculator::VERDICT_RESTOCK => 'warning',
                        RestockAnalysisCalculator::VERDICT_OVERSTOCK => 'info',
                        RestockAnalysisCalculator::VERDICT_OK => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('urgent_branch')->label('Cawangan Paling Perlu')->badge()->color('danger')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state, $record) => $state ? "{$state} (pernah jual {$record->urgent_branch_sold}x)" : null)
                    ->tooltip('Cawangan sold-out (stok=0) dgn jualan sejarah tertinggi utk design ni.'),
            ])
            ->filters([
                SelectFilter::make('category_code')->label('Kategori')
                    ->native()
                    ->searchable('CategoryCode')
                    ->options(fn () => Category::where('CategoryCode', '!=', '')->pluck('Description', 'CategoryCode')),
                SelectFilter::make('store_code')->label('Cawangan Paling Perlu')
                    ->native()
                    ->searchable('StoreCode')
                    ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode')),
                SelectFilter::make('verdict')->label('Cadangan')->options([
                    RestockAnalysisCalculator::VERDICT_SOLD_OUT => RestockAnalysisCalculator::VERDICT_SOLD_OUT,
                    RestockAnalysisCalculator::VERDICT_RESTOCK => RestockAnalysisCalculator::VERDICT_RESTOCK,
                    RestockAnalysisCalculator::VERDICT_OK => RestockAnalysisCalculator::VERDICT_OK,
                    RestockAnalysisCalculator::VERDICT_OVERSTOCK => RestockAnalysisCalculator::VERDICT_OVERSTOCK,
                    RestockAnalysisCalculator::VERDICT_NO_DATA => RestockAnalysisCalculator::VERDICT_NO_DATA,
                ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                Action::make('viewHistory')
                    ->slideOver()
                    ->label('Lihat Sejarah')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->modalHeading(fn ($record) => "Sejarah Jualan: {$record->internal_code} - {$record->description}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function ($record) {
                        $all = RestockAnalysisCalculator::designSalesHistory($record->internal_code);

                        // Bila penapis "Cawangan Paling Perlu" aktif di jadual induk, susun
                        // sejarah ni supaya cawangan tsb naik ke atas dahulu - sortBy() STABLE
                        // (PHP 8+), jadi susunan bulan-terkini-dahulu sedia ada kekal terpelihara
                        // DALAM setiap kumpulan (cawangan terpilih dahulu, baki lepas tu).
                        $priorityBranch = trim((string) ($this->tableFilters['store_code']['value'] ?? ''));

                        if (filled($priorityBranch)) {
                            $all = $all->sortBy(fn ($row) => $row['store_code'] === $priorityBranch ? 0 : 1)->values();
                        }

                        $shown = $all->take(self::HISTORY_MODAL_LIMIT)->values()->all();
                        $remaining = $all->count() - count($shown);

                        return [
                            TextEntry::make('empty_note')
                                ->hiddenLabel()
                                ->state('Tiada rekod jualan dlm 12 bulan kebelakangan utk design ni.')
                                ->color('gray')
                                ->visible($all->isEmpty()),
                            TextEntry::make('priority_note')
                                ->hiddenLabel()
                                ->state("Disusun ikut Cawangan Paling Perlu terpilih ({$priorityBranch}) dahulu.")
                                ->color('warning')
                                ->visible(filled($priorityBranch) && $all->isNotEmpty()),
                            RepeatableEntry::make('history')
                                ->hiddenLabel()
                                ->state($shown)
                                ->schema([
                                    TextEntry::make('month')->label('Bulan'),
                                    TextEntry::make('size')->label('Saiz'),
                                    TextEntry::make('weight_bucket')->label('Berat'),
                                    TextEntry::make('store_code')->label('Cawangan')->badge()
                                        ->color(fn ($state) => $state === $priorityBranch ? 'warning' : 'gray'),
                                    TextEntry::make('qty_sold')->label('Terjual')->numeric(),
                                ])
                                ->columns(5),
                            TextEntry::make('remaining_note')
                                ->hiddenLabel()
                                ->state("+ {$remaining} baris lain tidak dipaparkan.")
                                ->color('gray')
                                ->visible($remaining > 0),
                        ];
                    }),
            ])
            ->defaultSort('gap', 'desc')
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading('Pilih Kategori utk papar data')
            ->emptyStateDescription('Jadual ni sengaja kosong sehingga anda pilih Kategori di penapis atas.')
            ->emptyStateIcon(Heroicon::OutlinedTag);
    }
}
