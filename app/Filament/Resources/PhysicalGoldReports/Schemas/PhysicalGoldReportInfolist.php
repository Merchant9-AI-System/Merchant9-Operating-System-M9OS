<?php

namespace App\Filament\Resources\PhysicalGoldReports\Schemas;

use App\Models\PhysicalGoldCategory;
use App\Models\PhysicalGoldReport;
use App\Support\PhysicalGoldReportCalculator;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PhysicalGoldReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan Laporan')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('report_date')->label('Tarikh Laporan')->date('d/m/Y'),
                                TextEntry::make('status')->label('Status')->badge(),
                                TextEntry::make('gross_weight_total')->label('Jumlah Berat Kasar')
                                    ->state(fn (PhysicalGoldReport $r) => number_format(PhysicalGoldReportCalculator::grossWeightTotal($r), 4).' g'),
                                TextEntry::make('net_pure_weight')->label('Physical Net Pure Gold')
                                    ->state(fn (PhysicalGoldReport $r) => number_format(PhysicalGoldReportCalculator::netPureWeight($r), 4).' g'),
                                TextEntry::make('prepared_by')->label('Disediakan oleh'),
                                TextEntry::make('submitted_by')->label('Dihantar oleh')->placeholder('-'),
                                TextEntry::make('approved_by')->label('Diluluskan oleh')->placeholder('-'),
                                TextEntry::make('notes')->label('Nota')->placeholder('-'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Butiran Baris')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->schema([
                                Grid::make(6)
                                    ->schema([
                                        TextEntry::make('category.code')->label('Kategori')->badge()->color(fn (string $state): string => match ($state) {
                                            'USED_GOLD_HQ' => 'primary',
                                            'STOCK_BRANCH' => 'success',
                                            'STOCK_HQ' => 'info',
                                            'NEW_STOCK_SUPPLIER' => 'secondary',
                                            'GDN_PENDING' => 'warning',
                                            'SUPPLIER_OUTSTANDING' => 'danger',
                                            default => 'gray',
                                        }),
                                        // ->state(fn (string $state): string => PhysicalGoldCategory::find($state)->name),
                                        TextEntry::make('store.StoreCode')->label('Cawangan')->placeholder('-'),
                                        TextEntry::make('vendor.VendorCode')->label('Supplier')->placeholder('-'),
                                        TextEntry::make('purity.code')->label('Ketulenan')->placeholder('-'),
                                        TextEntry::make('gross_weight')->label('Berat Kasar')->numeric(4)->placeholder('-'),
                                        TextEntry::make('pure_weight')->label('Berat Tulen')->numeric(4)->placeholder('-'),
                                    ]),
                            ])
                            ->columns(1),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),
            ]);
    }
}
