<?php

namespace App\Filament\Pages;

use App\Jobs\SyncJemisysMirrors;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\User;
use App\Support\BranchFocusCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Cawangan mana perlu focus pada kategori mana - 100% drpd data JEMiSys sebenar
 * (rujuk BranchFocusCalculator). TIADA pergantungan PO/GRN/data manual.
 */
class BranchFocus extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.branch-focus';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Branch Focus';

    protected static string|\UnitEnum|null $navigationGroup = 'Analisis JEMiSys';

    protected static ?int $navigationSort = 4;

    /** Bilangan design maksimum dipaparkan dlm modal "Lihat Design" - senarai penuh boleh capai beratus baris. */
    private const DESIGNS_MODAL_LIMIT = 20;

    public function getSubheading(): ?string
    {
        $base = 'Cawangan mana perlu fokus (beli/jual) pada kategori mana - dikira 100% drpd data JEMiSys sebenar.';

        if (Cache::has(SyncJemisysMirrors::CACHE_KEY_SYNCING)) {
            return $base.' ⚠️ Data JEMiSys sedang disegerakkan sekarang - angka/senarai design mungkin tidak lengkap sementara sync berjalan.';
        }

        return $base;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage, ?array $filters, ?string $search, ?string $sortColumn, ?string $sortDirection) {
                // ->records() TIDAK auto-paginate/filter/search/sort spt ->query() - Filament
                // hantar SEMUA parameter ni terus ke closure, closure WAJIB uruskan semuanya
                // sendiri (rujuk Filament\Tables\Concerns\HasRecords::getTableRecords()).
                $all = BranchFocusCalculator::focus()
                    ->map(fn ($r, $i) => $r + ['InventoryCode' => 'bf_'.$i]);

                if ($storeCode = $filters['store_code']['value'] ?? null) {
                    $all = $all->where('store_code', $storeCode);
                }

                if ($categoryCode = $filters['category_code']['value'] ?? null) {
                    $all = $all->where('category_code', $categoryCode);
                }

                if ($focusArea = $filters['focus_area']['value'] ?? null) {
                    $all = $all->where('focus_area', $focusArea);
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
                TextColumn::make('store_code')->label('Cawangan')->badge()->sortable(),
                TextColumn::make('category_name')->label('Kategori')->searchable()->sortable(),
                TextColumn::make('current_stock')->label('Stok Semasa')->numeric()->sortable(),
                TextColumn::make('target_stock')->label('Stok Disyorkan (1.5 bulan)')->numeric()->sortable()
                    ->tooltip('Tahap stok disyorkan utk lindungi jualan 1.5 bulan pada kadar jualan semasa (Jualan/Bulan x 1.5)'),
                TextColumn::make('gap')->label('Gap')->numeric()->sortable()
                    ->tooltip('Stok Disyorkan - Stok Semasa. Positif = kurang stok (perlu restock), 0/negatif = cukup atau lebih.'),
                TextColumn::make('sell_through_rate')->label('% Terjual')
                    ->tooltip('Peratus item yang diterima & terjual dlm 3 bulan terkini sahaja (Terjual / Diterima, bukan sejarah penuh)')
                    ->formatStateUsing(fn ($state) => number_format($state * 100, 1).'%'),
                TextColumn::make('velocity_per_month')->label('Jualan/Bulan')->numeric(2)
                    ->tooltip('Purata jualan sebulan, dikira drpd 3 BULAN TERKINI sahaja (bukan sejarah penuh)'),
                TextColumn::make('pieces_sold_this_month')->label('Terjual Bulan Ini')->numeric()->sortable(),
                TextColumn::make('focus_area')->label('Cadangan Fokus')->badge()
                    ->color(fn ($state) => match ($state) {
                        'Understock - Fokus Beli' => 'danger',
                        'Overstock - Fokus Jual/Promosi' => 'warning',
                        'Seimbang' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('store_code')->label('Cawangan')
                    ->native()
                    // ->multiple()
                    ->searchable('StoreCode')
                    ->options(fn () => Store::whereNotIn('StoreCode', ['WEB', 'web'])->orderBy('StoreCode')->pluck('StoreCode', 'StoreCode')),
                SelectFilter::make('category_code')->label('Kategori')
                    ->native()
                    // ->multiple()
                    ->searchable('CategoryCode')
                    ->options(fn () => Category::where('CategoryCode', '!=', '')->pluck('Description', 'CategoryCode')),
                SelectFilter::make('focus_area')->label('Cadangan Fokus')->options([
                    'Understock - Fokus Beli' => 'Understock - Fokus Beli',
                    'Overstock - Fokus Jual/Promosi' => 'Overstock - Fokus Jual/Promosi',
                    'Seimbang' => 'Seimbang',
                    'Data Tak Cukup' => 'Data Tak Cukup',
                ]),
            ])
            ->recordActions([
                Action::make('viewDesigns')
                    ->slideOver()
                    ->label('Lihat Design')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->color('gray')
                    ->modalHeading(fn ($record) => "Design: {$record->category_name} · {$record->store_code}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function ($record) {
                        $all = BranchFocusCalculator::designsForFocus($record->store_code, $record->category_code);
                        $shown = $all->take(self::DESIGNS_MODAL_LIMIT)->values()->all();
                        $remaining = $all->count() - count($shown);

                        return [
                            TextEntry::make('scope_note')
                                ->hiddenLabel()
                                ->state($remaining > 0
                                    ? 'Menunjukkan '.self::DESIGNS_MODAL_LIMIT." design TERATAS drpd {$all->count()} jumlah keseluruhan - disusun ikut Terjual Bulan Ini tertinggi dahulu."
                                    : "Menunjukkan kesemua {$all->count()} design dlm kategori/cawangan ini.")
                                ->weight('bold')
                                ->color('warning')
                                ->visible($all->isNotEmpty()),
                            RepeatableEntry::make('designs')
                                ->hiddenLabel()
                                ->state($shown)
                                ->table([
                                    TableColumn::make('Kod Design'),
                                    TableColumn::make('Jenis Item'),
                                    TableColumn::make('Supplier'),
                                    TableColumn::make('Stok'),
                                    TableColumn::make('Terjual'),
                                    TableColumn::make('Terjual Bulan Ini'),
                                ])
                                ->schema([
                                    TextEntry::make('internal_code')->weight('bold'),
                                    TextEntry::make('description'),
                                    TextEntry::make('vendor_name'),
                                    TextEntry::make('current_stock')->numeric(),
                                    TextEntry::make('pieces_sold')->numeric(),
                                    TextEntry::make('sold_this_month')->numeric(),
                                ]),
                            TextEntry::make('empty_note')
                                ->hiddenLabel()
                                ->state('Tiada design dijumpai dalam kategori/cawangan ini.')
                                ->color('gray')
                                ->visible($all->isEmpty()),
                            TextEntry::make('remaining_note')
                                ->hiddenLabel()
                                ->state("+ {$remaining} design lain tidak dipaparkan.")
                                ->color('gray')
                                ->visible($remaining > 0),
                        ];
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('notifyBackOffice')
                        ->label('Notify Back Office')
                        ->icon(Heroicon::OutlinedBellAlert)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Hantar notifikasi kepada Back Office (CEO) utk semak item fokus yang dipilih?')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $lines = $records->map(fn ($r) => "- {$r->category_name} · {$r->store_code} (gap: {$r->gap}, {$r->focus_area})")->implode("\n");

                            $recipients = User::role('ceo')->get()->all();

                            Notification::make()
                                ->title($records->count().' item perlu fokus - sila semak (Branch Focus)')
                                ->body($lines)
                                ->warning()
                                ->actions([
                                    Action::make('gotoPage')->label('View')
                                        ->url(route('filament.admin.pages.branch-focus'))
                                        ->button(),
                                ])
                                ->sendToDatabase($recipients);

                            Notification::make()
                                ->title('Notifikasi dihantar ke Back Office')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('gap', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
