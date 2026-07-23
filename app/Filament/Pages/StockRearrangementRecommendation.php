<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\StockTransfer;
use App\Support\ProductImageFetcher;
use App\Support\StockRearrangementRecommender;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Facades\Auth;

/**
 * CEO Dashboard Phase 1 (E) - versi RINGKAS drpd cadangan rearrange, berasingan drpd page
 * Rearrange sedia ada (yg guna algoritma greedy multi-donor/multi-receiver). Rujuk
 * StockRearrangementRecommender utk rule. Boleh dimatikan via .env CEO_REARRANGEMENT_ENABLED=false
 * (config/dashboard.php). Kini turut ada tindakan tulis (View + Cipta Transfer) - sama spt
 * Rearrange, tapi setiap baris di sini dah tetap pada SATU pasangan from->to (bukan senarai).
 */
class StockRearrangementRecommendation extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.stock-rearrangement-recommendation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Cadangan Rearrange (Ringkas)';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 6;

    // public static function canAccess(): bool
    // {
    //     return (bool) config('dashboard.ceo_features.rearrangement', true);
    // }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Rule ringkas: design ada stok di Cawangan A, sold out di Cawangan B -> cadang pindah A ke B. '.
            'Boleh cipta transfer terus drpd sini (View utk butiran, atau Cipta Transfer terus).';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage, ?array $filters, ?string $search, ?array $columnSearches, ?string $sortColumn, ?string $sortDirection) {
                // ->records() TIDAK auto-paginate/filter/search/sort spt ->query() - Filament
                // hantar SEMUA parameter ni terus ke closure, closure WAJIB uruskan semuanya
                // sendiri (rujuk Filament\Tables\Concerns\HasRecords::getTableRecords()).
                $all = StockRearrangementRecommender::recommendations()
                    ->map(fn ($r, $i) => $r + ['InventoryCode' => 'sr_'.$i]);

                if ($fromBranch = $filters['from_branch']['value'] ?? null) {
                    $all = $all->where('from_branch', $fromBranch);
                }

                if ($toBranch = $filters['to_branch']['value'] ?? null) {
                    $all = $all->where('to_branch', $toBranch);
                }

                if ($priority = $filters['priority']['value'] ?? null) {
                    $all = $all->where('priority', $priority);
                }

                if (filled($excludedStores = $filters['store_code']['values'] ?? [])) {
                    // strtolower() kedua-dua belah - StoreCode kadangkala wujud dlm case berbeza
                    // antara jemisys_store_mirror (cth. "SECURITY") vs jemisys_inventory_mirror
                    // sebenar (cth. "security"), sama isu spt "WEB"/"web" (rujuk
                    // InventoryPiece::scopePhysicalStore()) - padanan case-sensitive x match.
                    $excludedStoresLower = array_map('strtolower', $excludedStores);
                    $all = $all->reject(fn ($r) => in_array(strtolower($r['from_branch']), $excludedStoresLower)
                        || in_array(strtolower($r['to_branch']), $excludedStoresLower));
                }

                if (filled($columnSearches['internal_code'] ?? null)) {
                    $needle = mb_strtolower($columnSearches['internal_code']);
                    $all = $all->filter(fn ($r) => str_contains(mb_strtolower((string) $r['internal_code']), $needle));
                }

                if (filled($columnSearches['item_desc'] ?? null)) {
                    $needle = mb_strtolower($columnSearches['item_desc']);
                    $all = $all->filter(fn ($r) => str_contains(mb_strtolower((string) $r['item_desc']), $needle));
                }

                if (filled($search)) {
                    $needle = mb_strtolower($search);
                    $all = $all->filter(fn ($r) => str_contains(mb_strtolower((string) $r['internal_code']), $needle));
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
                ImageColumn::make('InternalCodeImage')
                    ->label('Imej')
                    ->state(fn ($record) => ProductImageFetcher::firstImageUrlFor($record->internal_code))
                    ->imageHeight(50)
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->url(fn ($record) => ProductImageFetcher::firstImageUrlFor($record->internal_code))
                    ->openUrlInNewTab()
                    ->placeholder('No image'),
                TextColumn::make('from_branch')->label('From Branch')->badge()->color('success'),
                TextColumn::make('to_branch')->label('To Branch')->badge()->color('danger'),
                TextColumn::make('internal_code')->label('Design / SKU')->searchable(isIndividual: true),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25)->searchable(isIndividual: true),
                TextColumn::make('current_stock')->label('Current Stock')->numeric()->sortable(),
                TextColumn::make('reason')->label('Reason')->wrap(),
                TextColumn::make('priority')->label('Priority')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state) => match ($state) {
                        StockRearrangementRecommender::HIGH => 'danger',
                        StockRearrangementRecommender::MEDIUM => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('from_branch')->label('From Branch')
                    ->options(fn () => StockRearrangementRecommender::recommendations()->pluck('from_branch', 'from_branch')->unique()->sort()),
                SelectFilter::make('to_branch')->label('To Branch')
                    ->options(fn () => StockRearrangementRecommender::recommendations()->pluck('to_branch', 'to_branch')->unique()->sort()),

                // Tiada ->query() di sini sengaja - table ni guna ->records() (bukan ->query()),
                // jadi Filament TIDAK PERNAH panggil closure ->query() filter (rujuk
                // Filament\Tables\Concerns\HasRecords::getTableRecords() cabang "! hasQuery()" -
                // terus evaluate getDataSource(), tak pernah applyFiltersToTableQuery()). Logik
                // sebenar exclude Cawangan (from_branch/to_branch, BUKAN StoreCode - lajur tsb
                // tiada pd baris rekomendasi) dikendalikan terus dlm closure ->records() di atas.
                SelectFilter::make('store_code')->label('Exclude Cawangan')
                    ->native()
                    ->multiple()
                    ->searchable('StoreCode')
                    ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode')),
                SelectFilter::make('priority')->label('Priority')->options([
                    StockRearrangementRecommender::HIGH => StockRearrangementRecommender::HIGH,
                    StockRearrangementRecommender::MEDIUM => StockRearrangementRecommender::MEDIUM,
                    StockRearrangementRecommender::LOW => StockRearrangementRecommender::LOW,
                ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->slideOver()
                    ->modalHeading(fn ($record) => "Cadangan Pindah: {$record->internal_code}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(fn ($record) => [
                        TextEntry::make('receiver_label')
                            ->label('Cawangan Perlu (sold out, pernah jual)')
                            ->state($record->receiver_label),
                        TextEntry::make('suggestion')
                            ->label('Cadangan Pindahan')
                            ->state($record->suggestion),
                    ])
                    ->extraModalFooterActions([
                        static::createTransferAction(),
                    ]),
                static::createTransferAction(),
            ])
            ->paginated([10, 25, 50, 100])
            ->searchPlaceholder('Cari kod design...');
    }

    private static function createTransferAction(): Action
    {
        return Action::make('createTransfer')
            ->label('Cipta Transfer')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('success')
            ->schema(fn ($record) => [
                Select::make('from_store')
                    ->label('Daripada Cawangan')
                    ->options(fn () => [$record->from_branch => $record->from_branch])
                    ->default($record->from_branch)
                    ->required(),
                Select::make('to_store')
                    ->label('Ke Cawangan')
                    ->options(fn () => [$record->to_branch => $record->to_branch])
                    ->default($record->to_branch)
                    ->required(),
                TextInput::make('qty')->label('Kuantiti')->numeric()->minValue(1)->default(1)->required(),
            ])
            ->action(function (array $data, $record) {
                $t = StockTransfer::create([
                    'internal_code' => $record->internal_code,
                    'item_desc' => $record->item_desc,
                    'category_code' => $record->category_name,
                    'from_store' => $data['from_store'],
                    'to_store' => $data['to_store'],
                    'qty' => $data['qty'],
                    'requested_by' => Auth::user()->name,
                ]);
                Notification::make()->title("Transfer {$t->transfer_number} dicipta")->success()->send();
            });
    }
}
