<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\Vendor;
use App\Support\SupplierPerformanceCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Supplier mana mahal (avg kos seunit), margin tinggi/rendah, fast-moving - 100% drpd data
 * JEMiSys sebenar (rujuk SupplierPerformanceCalculator). TIADA pergantungan PO/GRN.
 */
class SupplierPerformance extends Page implements HasTable
{
    use InteractsWithTable, HasPageShield;

    protected string $view = 'filament.pages.supplier-performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $navigationLabel = 'Prestasi Supplier';

    protected static string|\UnitEnum|null $navigationGroup = 'Analisis JEMiSys';

    protected static ?int $navigationSort = 3;

    public function getSubheading(): ?string
    {
        return 'Supplier mahal, margin tinggi/rendah, & fast-moving rating - dikira 100% drpd data JEMiSys sebenar. '.
            'Margin cuma dikira drpd baris yg ada SalesAmount (~61% liputan data) - lihat lajur "Sampel Margin".';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => Vendor::hydrate(SupplierPerformanceCalculator::performance()->all()))
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
                TextColumn::make('velocity_per_month')->label('Fast-Moving Rating (Jualan/Bulan)')->numeric(2)->sortable(),
                TextColumn::make('sell_through_rate')->label('% Terjual')
                    ->formatStateUsing(fn ($state) => number_format($state * 100, 1).'%')
                    ->sortable(),
                TextColumn::make('current_stock')->label('Stok Semasa')->numeric(),
            ])
            ->defaultSort('avg_unit_cost', 'desc')
            ->paginated([25, 50, 100]);
    }

    public function getTableRecordKey($record): string
    {
        return (string) $record->vendor_code;
    }
}
