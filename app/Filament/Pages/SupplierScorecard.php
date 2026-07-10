<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\Vendor;
use App\Support\SupplierScorecardCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Prestasi supplier dikira drpd data PO+GRN sebenar (rujuk SupplierScorecardCalculator).
 * Kosong sehingga ada PO sebenar dicipta & diterima - itu betul, bukan bug.
 */
class SupplierScorecard extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.supplier-scorecard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $navigationLabel = 'Supplier Scorecard';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 5;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => Vendor::hydrate(
                SupplierScorecardCalculator::scorecard()->all()
            ))
            ->columns([
                TextColumn::make('vendor_name')->label('Supplier')->searchable(),
                TextColumn::make('total_po')->label('Jumlah PO')->numeric()->sortable(),
                TextColumn::make('total_spend')->label('Jumlah Belanja')->money('MYR')->sortable(),
                TextColumn::make('fill_rate')->label('Fill Rate')
                    ->formatStateUsing(fn ($state) => $state !== null ? "{$state}%" : '-')
                    ->badge()
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 90 ? 'success' : ($state >= 70 ? 'warning' : 'danger'))),
                TextColumn::make('avg_lead_time_days')->label('Purata Lead Time (hari)')
                    ->formatStateUsing(fn ($state) => $state ?? '-')
                    ->sortable(),
                TextColumn::make('po_received_count')->label('PO Selesai Diterima')->numeric(),
            ])
            ->defaultSort('total_spend', 'desc')
            ->paginated([25, 50, 100]);
    }

    public function getTableRecordKey($record): string
    {
        return (string) $record->vendor_code;
    }
}
