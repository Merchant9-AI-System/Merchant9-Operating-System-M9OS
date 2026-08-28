<?php

namespace App\Filament\Pages;

use App\Jobs\SyncJemisysMirrors;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\User;
use App\Support\RestockAnalysisCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

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

    /** Bilangan design maksimum dipaparkan dlm modal "Lihat Design" - senarai penuh boleh capai beratus baris. */
    private const DESIGNS_MODAL_LIMIT = 20;

    public function getSubheading(): ?string
    {
        $base = 'Cadangan restock ikut Berat Emas, silang Kategori x Cawangan - dikira 100% drpd data JEMiSys sebenar.';

        if (Cache::has(SyncJemisysMirrors::CACHE_KEY_SYNCING)) {
            return $base.' ⚠️ Data JEMiSys sedang disegerakkan sekarang - angka/senarai design mungkin tidak lengkap sementara sync berjalan.';
        }

        return $base;
    }

    /** Label Bahasa Melayu bagi penapis "Tempoh" semasa - papar pd lajur Jualan/Bulan.
     *  Penapis "Tempoh" DISOROK drpd UI buat masa ni (rujuk SelectFilter::make('period') dikomen
     *  bawah) - jumlah (Jualan/Diterima) kini SENTIASA "Semua" (PERIOD_ALL, tiada sempadan
     *  tarikh), TIADA lagi nilai lain boleh terhasil drpd tableFilters (filter dah disorok),
     *  jadi label kekal tetap. Velocity/Cadangan KEKAL guna tetingkap TREND_MONTHS sebenar
     *  (rujuk RestockAnalysisCalculator::finalize() dokblok PERIOD_ALL) - bukan label ni. */
    protected function currentPeriodLabel(): string
    {
        return 'Semua';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage, ?array $filters, ?string $search, ?string $sortColumn, ?string $sortDirection) {
                // ->records() TIDAK auto-paginate/filter/search/sort spt ->query() - Filament
                // hantar SEMUA parameter ni terus ke closure, closure WAJIB uruskan semuanya
                // sendiri (rujuk Filament\Tables\Concerns\HasRecords::getTableRecords()).
                // PERIOD_ALL - rujuk nota currentPeriodLabel() di atas (penapis "Tempoh" disorok
                // drpd UI, jumlah kini SENTIASA "Semua").
                $period = RestockAnalysisCalculator::PERIOD_ALL;

                $all = RestockAnalysisCalculator::byWeight($period)
                    ->map(fn ($r, $i) => $r + ['InventoryCode' => 'rbw_'.$i]);

                if ($categoryCode = $filters['category_code']['value'] ?? null) {
                    $all = $all->where('category_code', $categoryCode);
                }

                if ($storeCode = $filters['store_code']['value'] ?? null) {
                    // trim() kedua-dua belah - store_code drpd RestockAnalysisCalculator::bySize()/
                    // byWeight() SEBENARNYA padded (CHAR(20) mentah, disahkan Tinker), BUKAN
                    // trim()-ed spt didakwa nota lama di bawah (SelectFilter) - tanpa trim() di
                    // sini, filter Cawangan senyap pulangkan 0 baris.
                    $storeCode = trim($storeCode);
                    $all = $all->filter(fn ($r) => trim((string) $r['store_code']) === $storeCode);
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
                TextColumn::make('target_stock')->label('Stok Disyorkan (1.5 bulan)')->numeric()->sortable()
                    ->tooltip('Tahap stok disyorkan utk lindungi jualan 1.5 bulan pada kadar jualan semasa (Jualan/Bulan x 1.5)'),
                TextColumn::make('gap')->label('Gap')->numeric()->sortable()
                    ->tooltip('Stok Disyorkan - Stok Semasa. Positif = kurang stok (perlu restock), 0/negatif = cukup atau lebih.')
                    ->color(fn ($state) => $state > 0 ? 'danger' : ($state < 0 ? 'warning' : 'success')),
                TextColumn::make('pieces_sold')->numeric()->sortable()
                    ->label(fn () => 'Jualan ('.$this->currentPeriodLabel().')')
                    ->tooltip(fn () => 'Bilangan keping terjual dlm tempoh "'.$this->currentPeriodLabel().'" terkini (ikut penapis Tempoh) - angka MENTAH, bukan anggaran sebulan.'),
                // Disorok lalai (bukan dibuang) - anggaran "kalau kadar ni berterusan sebulan",
                // amat tidak stabil pd tempoh singkat. Kekal boleh capai via toggle lajur.
                TextColumn::make('velocity_per_month')->numeric(2)
                    ->label(fn () => 'Jualan/Bulan ('.$this->currentPeriodLabel().')')
                    ->tooltip(fn () => 'Purata jualan sebulan, dikira drpd tempoh "'.$this->currentPeriodLabel().'" terkini sahaja (ikut penapis Tempoh, bukan sejarah penuh) - tukar penapis Tempoh utk lihat jangka masa lain.')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                // Penapis "Tempoh" DISOROK drpd UI buat masa ni (kekal dikomen utk kegunaan
                // akan datang) - fungsi/fallback PERIOD_1_WEEK kekal (rujuk currentPeriodLabel()
                // & closure ->records() di atas).
                // SelectFilter::make('period')->label('Tempoh')
                //     ->native()
                //     ->default(RestockAnalysisCalculator::PERIOD_1_WEEK)
                //     ->options(RestockAnalysisCalculator::PERIOD_LABELS),
                SelectFilter::make('category_code')->label('Kategori')
                    ->native()
                    ->searchable('CategoryCode')
                    ->options(fn () => Category::where('CategoryCode', '!=', '')->pluck('Description', 'CategoryCode')),
                SelectFilter::make('store_code')->label('Cawangan')
                    ->native()
                    // ->multiple()
                    ->searchable('StoreCode')
                    // Pilihan trim()-ed drpd Store, TAPI store_code drpd kalkulator (bySize/
                    // byWeight) SEBENARNYA padded (disahkan Tinker sesi ni) - trim() perbandingan
                    // di ->records() atas (bukan sekadar di sini) yg pastikan padanan sebenar.
                    ->options(fn () => Store::orderBy('StoreCode')->get()->mapWithKeys(fn ($s) => [trim($s->StoreCode) => trim($s->StoreCode)]))
                    // Default terus ke cawangan user log masuk (kosong/null utk HQ/CEO - Filament
                    // anggap null sbg "tiada default", filter papar All spt biasa).
                    ->default(fn () => trim((string) auth()->user()?->store_code) ?: null),
                SelectFilter::make('verdict')->label('Cadangan')->options([
                    RestockAnalysisCalculator::VERDICT_SOLD_OUT => RestockAnalysisCalculator::VERDICT_SOLD_OUT,
                    RestockAnalysisCalculator::VERDICT_RESTOCK => RestockAnalysisCalculator::VERDICT_RESTOCK,
                    RestockAnalysisCalculator::VERDICT_OK => RestockAnalysisCalculator::VERDICT_OK,
                    RestockAnalysisCalculator::VERDICT_OVERSTOCK => RestockAnalysisCalculator::VERDICT_OVERSTOCK,
                    RestockAnalysisCalculator::VERDICT_NO_DATA => RestockAnalysisCalculator::VERDICT_NO_DATA,
                ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->recordActions([
                Action::make('viewDesigns')
                    ->slideOver()
                    ->label('Lihat Design')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->color('gray')
                    ->modalHeading(fn ($record) => "Design: {$record->category_name} · {$record->store_code} · Berat {$record->bucket}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function ($record) {
                        $all = RestockAnalysisCalculator::designsForWeightBucket($record->category_code, $record->store_code, $record->bucket);
                        $shown = $all->take(self::DESIGNS_MODAL_LIMIT)->values()->all();
                        $remaining = $all->count() - count($shown);

                        return [
                            TextEntry::make('scope_note')
                                ->hiddenLabel()
                                ->state($remaining > 0
                                    ? 'Menunjukkan '.self::DESIGNS_MODAL_LIMIT." design TERATAS drpd {$all->count()} jumlah keseluruhan - disusun ikut Terjual Bulan Ini tertinggi dahulu."
                                    : "Menunjukkan kesemua {$all->count()} design dlm bucket ini.")
                                ->weight('bold')
                                ->color('warning')
                                ->visible($all->isNotEmpty()),
                            RepeatableEntry::make('designs')
                                ->hiddenLabel()
                                ->state($shown)
                                ->schema([
                                    ImageEntry::make('image_url')->hiddenLabel()->square()->imageSize(50)
                                        ->placeholder('No image'),
                                    TextEntry::make('internal_code')->label('Kod Design')->weight('bold'),
                                    TextEntry::make('description')->label('Jenis Item'),
                                    TextEntry::make('vendor_name')->label('Supplier'),
                                    TextEntry::make('current_stock')->label('Stok')->numeric(),
                                    TextEntry::make('pieces_sold')->label('Terjual')->numeric(),
                                    TextEntry::make('sold_this_month')->label('Terjual Bulan Ini')->numeric(),
                                ])
                                ->columns(4),
                            TextEntry::make('empty_note')
                                ->hiddenLabel()
                                ->state('Tiada design dijumpai dalam bucket ini.')
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
                        ->modalDescription('Hantar notifikasi kepada Back Office (CEO) utk semak item restock yang dipilih?')
                        ->schema([
                            Select::make('recipient_user_ids')
                                ->label('Notify Users')
                                ->multiple()
                                ->searchable()
                                ->options(fn () => User::notifiable()->orderBy('name')->pluck('name', 'id'))
                                ->required(),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data) {
                            $lines = $records->map(fn ($r) => "- {$r->category_name} · {$r->store_code} · Berat {$r->bucket} (gap: {$r->gap})")->implode("\n");

                            $recipients = User::whereIn('id', $data['recipient_user_ids'])->get()->all();

                            Notification::make()
                                ->title($records->count().' item perlu restock - sila semak (Restock ikut Berat)')
                                ->body($lines)
                                ->warning()
                                ->actions([
                                    Action::make('gotoPage')->label('View')
                                        ->url(route('filament.admin.pages.restock-by-weight'))
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
