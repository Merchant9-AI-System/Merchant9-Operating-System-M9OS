<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\Vendor;
use App\Support\RestockAnalysisCalculator;
use App\Support\SupplierPerformanceCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Supplier mana mahal (avg kos seunit), margin tinggi/rendah, fast-moving - 100% drpd data
 * JEMiSys sebenar (rujuk SupplierPerformanceCalculator). TIADA pergantungan PO/GRN.
 */
class SupplierPerformance extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.supplier-performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Prestasi Supplier';

    protected static string|\UnitEnum|null $navigationGroup = 'Analisis JEMiSys';

    protected static ?int $navigationSort = 3;

    public function getSubheading(): ?string
    {
        return 'Supplier mahal, margin tinggi/rendah, & fast-moving rating - dikira 100% drpd data JEMiSys sebenar. '.
            'Margin cuma dikira drpd baris yg ada SalesAmount (~61% liputan data) - lihat lajur "Sampel Margin".';
    }

    /** Label Bahasa Melayu bagi penapis "Tempoh" semasa - papar pd lajur Jualan(/Bulan) & %
     *  Terjual supaya pengguna sedar angka tsb ikut tempoh yg dipilih, bukan sentiasa 3 bulan. */
    protected function currentPeriodLabel(): string
    {
        $period = $this->tableFilters['period']['value'] ?? RestockAnalysisCalculator::DEFAULT_PERIOD;

        return RestockAnalysisCalculator::PERIOD_LABELS[$period] ?? RestockAnalysisCalculator::PERIOD_LABELS[RestockAnalysisCalculator::DEFAULT_PERIOD];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage, ?array $filters, ?string $search, ?string $sortColumn, ?string $sortDirection) {
                // ->records() TIDAK auto-paginate/search/sort spt ->query() - Filament hantar
                // SEMUA parameter ni terus ke closure, closure WAJIB uruskan semuanya sendiri
                // (rujuk Filament\Tables\Concerns\HasRecords::getTableRecords()).
                // Vendor->$primaryKey = 'VendorCode' (PascalCase), tapi calculator pulang
                // 'vendor_code' (snake_case) - tanpa map ni, getKey() model pulang null utk
                // SEMUA baris (mismatch attribute), Filament re-key ikut getKey() dlm
                // getTableRecords() lalu SEMUA baris bertindih jadi SATU baris sahaja.
                $period = $filters['period']['value'] ?? RestockAnalysisCalculator::DEFAULT_PERIOD;

                $all = SupplierPerformanceCalculator::performance($period)
                    ->map(fn ($r) => $r + ['VendorCode' => $r['vendor_code']]);

                if (filled($search)) {
                    $needle = mb_strtolower($search);
                    $all = $all->filter(fn ($r) => str_contains(mb_strtolower((string) $r['vendor_name']), $needle));
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
                    Vendor::hydrate($all->forPage($page, $recordsPerPage)->values()->all()),
                    $all->count(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('vendor_name')->label('Supplier')->searchable(),
                TextColumn::make('avg_unit_cost')->label('Avg Kos/Unit')->money('MYR')->sortable(),
                TextColumn::make('stock_value')->label('Nilai Stok Semasa')->money('MYR')->sortable(),
                TextColumn::make('margin_pct')->label('Margin')
                    ->formatStateUsing(fn ($state) => $state !== null ? "{$state}%" : 'Tiada Data')
                    ->badge()
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 20 ? 'success' : ($state >= 0 ? 'warning' : 'danger'))),
                TextColumn::make('margin_sample_size')->label('Sampel Margin')->numeric()
                    ->tooltip('Bilangan baris terjual dgn SalesAmount tersedia - margin kurang tepat kalau sampel kecil'),
                TextColumn::make('pieces_sold')->numeric()->sortable()
                    ->label(fn () => 'Jualan ('.$this->currentPeriodLabel().')')
                    ->tooltip(fn () => 'Bilangan keping terjual dlm tempoh "'.$this->currentPeriodLabel().'" terkini (ikut penapis Tempoh) - angka MENTAH, bukan anggaran sebulan.'),
                // Disorok lalai (bukan dibuang) - "Fast-Moving Rating" anggaran "kalau kadar ni
                // berterusan sebulan", amat tidak stabil pd tempoh singkat. Kekal boleh capai via
                // toggle lajur.
                TextColumn::make('velocity_per_month')->numeric(2)->sortable()
                    ->label(fn () => 'Fast-Moving Rating - Jualan/Bulan ('.$this->currentPeriodLabel().')')
                    ->tooltip(fn () => 'Purata jualan sebulan, dikira drpd tempoh "'.$this->currentPeriodLabel().'" terkini sahaja (ikut penapis Tempoh, bukan sejarah penuh) - tukar penapis Tempoh utk lihat jangka masa lain.')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sell_through_rate')
                    ->label(fn () => '% Terjual ('.$this->currentPeriodLabel().')')
                    ->tooltip(fn () => 'Peratus item yang diterima & terjual dlm tempoh "'.$this->currentPeriodLabel().'" terkini (Terjual / Diterima, ikut penapis Tempoh)')
                    ->formatStateUsing(fn ($state) => number_format($state * 100, 1).'%')
                    ->sortable(),
                TextColumn::make('current_stock')->label('Stok Semasa')->numeric(),
            ])
            ->filters([
                // Tempoh trend utk Fast-Moving Rating, % Terjual, pieces_received & pieces_sold -
                // TIDAK menjejaskan Margin (dikira merentas SEMUA sejarah jualan, tiada tetingkap masa).
                SelectFilter::make('period')->label('Tempoh')
                    ->native()
                    ->default(RestockAnalysisCalculator::DEFAULT_PERIOD)
                    ->options(RestockAnalysisCalculator::PERIOD_LABELS),
            ], layout: FiltersLayout::AboveContent)
            ->defaultSort('avg_unit_cost', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    public function getTableRecordKey($record): string
    {
        return (string) $record->vendor_code;
    }
}
