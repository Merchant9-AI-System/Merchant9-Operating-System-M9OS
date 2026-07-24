<?php

namespace App\Filament\Resources\PhysicalGoldReports\Schemas;

use App\Models\PhysicalGoldReport;
use App\Support\PhysicalGoldReconciliationCalculator;
use App\Support\PhysicalGoldReportCalculator;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class PhysicalGoldReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan Laporan')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->iconColor('primary')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('report_date')->label('Tarikh Laporan')->date('d/m/Y'),
                                TextEntry::make('status')->label('Status')->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        PhysicalGoldReport::STATUS_DRAFT => 'gray',
                                        PhysicalGoldReport::STATUS_SUBMITTED => 'warning',
                                        PhysicalGoldReport::STATUS_APPROVED => 'success',
                                        default => 'gray',
                                    }),
                                TextEntry::make('gross_weight_total')->label('Jumlah Berat Kasar')
                                    ->state(fn (PhysicalGoldReport $r) => number_format(PhysicalGoldReportCalculator::grossWeightTotal($r), 4).' g'),
                                TextEntry::make('net_pure_weight')->label('Physical Net Pure Gold')
                                    ->state(fn (PhysicalGoldReport $r) => number_format(PhysicalGoldReportCalculator::netPureWeight($r), 4).' g')
                                    ->weight('bold'),
                                TextEntry::make('prepared_by')->label('Disediakan oleh'),
                                TextEntry::make('submitted_by')->label('Dihantar oleh')->placeholder('-'),
                                TextEntry::make('approved_by')->label('Diluluskan oleh')->placeholder('-'),
                                TextEntry::make('notes')->label('Nota')->placeholder('-'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Gold Reconciliation')
                    ->icon(Heroicon::OutlinedScale)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('reconciliation_status')
                                    ->label('Status')
                                    ->state(fn (PhysicalGoldReport $r) => static::reconciliation($r)['status'])
                                    ->badge()
                                    ->formatStateUsing(fn (string $state) => match ($state) {
                                        PhysicalGoldReconciliationCalculator::STATUS_GREEN => 'Sepadan',
                                        PhysicalGoldReconciliationCalculator::STATUS_YELLOW => 'Amaran',
                                        PhysicalGoldReconciliationCalculator::STATUS_RED => 'Kritikal',
                                        PhysicalGoldReconciliationCalculator::STATUS_PENDING => 'Book Balance Pending',
                                        default => $state,
                                    })
                                    ->color(fn (string $state) => match ($state) {
                                        PhysicalGoldReconciliationCalculator::STATUS_GREEN => 'success',
                                        PhysicalGoldReconciliationCalculator::STATUS_YELLOW => 'warning',
                                        PhysicalGoldReconciliationCalculator::STATUS_RED => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('book_net_weight')
                                    ->label('Book Net Weight')
                                    ->state(fn (PhysicalGoldReport $r) => static::formatOrPending(static::reconciliation($r)['book_net_weight'])),
                                TextEntry::make('variance')
                                    ->label('Variance')
                                    ->state(fn (PhysicalGoldReport $r) => static::formatOrPending(static::reconciliation($r)['variance']))
                                    ->color(fn (PhysicalGoldReport $r) => match (static::reconciliation($r)['status']) {
                                        PhysicalGoldReconciliationCalculator::STATUS_RED => 'danger',
                                        PhysicalGoldReconciliationCalculator::STATUS_YELLOW => 'warning',
                                        PhysicalGoldReconciliationCalculator::STATUS_GREEN => 'success',
                                        default => 'gray',
                                    }),
                                TextEntry::make('variance_pct')
                                    ->label('Variance %')
                                    ->state(fn (PhysicalGoldReport $r) => ($v = static::reconciliation($r)['variance_pct']) !== null
                                        ? number_format($v, 2).'%'
                                        : '-'),
                                TextEntry::make('book_closing_stock')
                                    ->label('Book Closing Stock (rujukan)')
                                    ->state(fn (PhysicalGoldReport $r) => static::formatOrPending(static::reconciliation($r)['book_closing_stock'])),
                                TextEntry::make('variance_vs_closing_stock')
                                    ->label('Variance vs Closing Stock (rujukan)')
                                    ->state(fn (PhysicalGoldReport $r) => static::formatOrPending(static::reconciliation($r)['variance_vs_closing_stock'])),
                                TextEntry::make('day_on_day_movement')
                                    ->label('Pergerakan Fizikal Harian')
                                    ->state(fn (PhysicalGoldReport $r) => ($v = static::reconciliation($r)['day_on_day_movement']) !== null
                                        ? number_format($v, 4).' g'
                                        : 'Tiada laporan Approved sebelum ini'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Ringkasan Kategori')
                    ->icon(Heroicon::OutlinedChartPie)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('category_breakdown')
                            ->label('')
                            ->state(fn (PhysicalGoldReport $r) => PhysicalGoldReportCalculator::categoryBreakdown($r))
                            ->table([
                                TableColumn::make('Kategori'),
                                TableColumn::make('Berat Kasar (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                            ])
                            ->schema([
                                TextEntry::make('category.name')->label('Kategori'),
                                TextEntry::make('gross_weight')->label('Berat Kasar')->numeric(4),
                                TextEntry::make('pure_weight')->label('Berat Tulen')->numeric(4),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Used Gold at HQ')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->iconColor('primary')
                    ->collapsible()
                    ->visible(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'USED_GOLD_HQ')->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('used_gold_hq_lines')
                            ->label('')
                            ->state(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'USED_GOLD_HQ'))
                            ->table([
                                TableColumn::make('Ketulenan'),
                                TableColumn::make('Berat Kasar (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                                TableColumn::make('Catatan'),
                            ])
                            ->schema([
                                TextEntry::make('purity.code')->label('Ketulenan'),
                                TextEntry::make('gross_weight')->label('Berat Kasar')->numeric(4),
                                TextEntry::make('pure_weight')->label('Berat Tulen')->numeric(4),
                                TextEntry::make('remarks')->label('Catatan')->placeholder('-'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Stock at Branch')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->iconColor('primary')
                    ->collapsible()
                    ->visible(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'STOCK_BRANCH')->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('stock_branch_lines')
                            ->label('')
                            ->state(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'STOCK_BRANCH'))
                            ->table([
                                TableColumn::make('Cawangan'),
                                TableColumn::make('Berat Kasar (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                            ])
                            ->schema([
                                TextEntry::make('store_code')->label('Cawangan'),
                                TextEntry::make('gross_weight')->label('Berat Kasar')->numeric(4),
                                TextEntry::make('pure_weight')->label('Berat Tulen')->numeric(4),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Stock at HQ')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->iconColor('primary')
                    ->collapsible()
                    ->compact()
                    ->visible(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'STOCK_HQ')->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('stock_hq_lines')
                            ->label('')
                            ->state(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'STOCK_HQ'))
                            ->table([
                                TableColumn::make('Cawangan'),
                                TableColumn::make('Berat Kasar (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                            ])
                            ->schema([
                                TextEntry::make('store_code')->label('Cawangan'),
                                TextEntry::make('gross_weight')->label('Berat Kasar')->numeric(4),
                                TextEntry::make('pure_weight')->label('Berat Tulen')->numeric(4),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('New Stock Not Yet Key-in')
                    ->icon(Heroicon::OutlinedTruck)
                    ->iconColor('primary')
                    ->collapsible()
                    ->visible(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'NEW_STOCK_SUPPLIER')->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('new_stock_lines')
                            ->label('')
                            ->state(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'NEW_STOCK_SUPPLIER'))
                            ->table([
                                TableColumn::make('Supplier'),
                                TableColumn::make('Berat Kasar (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                            ])
                            ->schema([
                                TextEntry::make('vendor.Description')->label('Supplier')->placeholder(fn ($record) => $record->vendor_code),
                                TextEntry::make('gross_weight')->label('Berat Kasar')->numeric(4),
                                TextEntry::make('pure_weight')->label('Berat Tulen')->numeric(4),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('GDN Not Yet Received / Not Weighed')
                    ->icon(Heroicon::OutlinedSquaresPlus)
                    ->iconColor('primary')
                    ->collapsible()
                    ->visible(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'GDN_PENDING')->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('gdn_pending_lines')
                            ->label('')
                            ->state(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'GDN_PENDING'))
                            ->table([
                                TableColumn::make('Ketulenan'),
                                TableColumn::make('Berat Kasar (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                                TableColumn::make('Dari'),
                                TableColumn::make('Hingga'),
                                TableColumn::make('Catatan'),
                            ])
                            ->schema([
                                TextEntry::make('purity.code')->label('Ketulenan'),
                                TextEntry::make('gross_weight')->label('Berat Kasar')->numeric(4),
                                TextEntry::make('pure_weight')->label('Berat Tulen')->numeric(4),
                                TextEntry::make('date_range_from')->label('Dari')->date('d/m/Y')->placeholder('-'),
                                TextEntry::make('date_range_to')->label('Hingga')->date('d/m/Y')->placeholder('-'),
                                TextEntry::make('remarks')->label('Catatan')->placeholder('-'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Outstanding Gold Due to Suppliers')
                    ->icon(Heroicon::OutlinedBriefcase)
                    ->iconColor('primary')
                    ->collapsible()
                    ->visible(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'SUPPLIER_OUTSTANDING')->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('supplier_outstanding_lines')
                            ->label('')
                            ->state(fn (PhysicalGoldReport $r) => static::categoryLines($r, 'SUPPLIER_OUTSTANDING'))
                            ->table([
                                TableColumn::make('Supplier'),
                                TableColumn::make('Payable Kasar (g)'),
                                TableColumn::make('Payable Tulen (g)'),
                                TableColumn::make('Receivable Kasar (g)'),
                                TableColumn::make('Receivable Tulen (g)'),
                            ])
                            ->schema([
                                TextEntry::make('vendor.Description')->label('Supplier')->placeholder(fn ($record) => $record->vendor_code),
                                TextEntry::make('payable_gross_weight')->label('Payable Kasar')->numeric(4)->placeholder('-'),
                                TextEntry::make('payable_pure_weight')->label('Payable Tulen')->numeric(4)->placeholder('-')->color('danger'),
                                TextEntry::make('receivable_gross_weight')->label('Receivable Kasar')->numeric(4)->placeholder('-'),
                                TextEntry::make('receivable_pure_weight')->label('Receivable Tulen')->numeric(4)->placeholder('-')->color('success'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /** Memoized per rekod - dielak kira semula reconcile() (query+cache) setiap medan dlm seksyen ni. */
    protected static function reconciliation(PhysicalGoldReport $report): array
    {
        static $cache = null;

        return $cache ??= PhysicalGoldReconciliationCalculator::reconcile($report);
    }

    /** Baris drpd kategori tertentu - dikumpul sekali per rekod (bukan query berulang per seksyen). */
    protected static function categoryLines(PhysicalGoldReport $report, string $code): Collection
    {
        static $grouped = null;
        $grouped ??= $report->lines->groupBy('category.code');

        return $grouped->get($code, collect());
    }

    protected static function formatOrPending(?float $value): string
    {
        return $value !== null ? number_format($value, 4).' g' : 'Belum Tersedia';
    }
}
