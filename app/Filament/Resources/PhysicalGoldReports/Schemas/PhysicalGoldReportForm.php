<?php

namespace App\Filament\Resources\PhysicalGoldReports\Schemas;

use App\Models\Jemisys\Vendor;
use App\Support\PhysicalGoldReportLineMapper;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Borang disusun ikut seksyen TETAP sepadan Weekly Stock Report sebenar - TIADA pilihan
 * kategori manual (setiap seksyen sudah tetap kategori dia), ketulenan sudah "stated" utk
 * Used Gold at HQ & GDN (satu baris pra-isi per ketulenan aktif), cawangan pra-isi utk Stock
 * at Branch (semua cawangan aktif kecuali HQ/SECURITY), Stock at HQ medan tunggal. New Stock
 * & Outstanding kekal repeater bebas (supplier pelbagai), guna faktor ketulenan "blended" 930
 * automatik (rujuk App\Support\PhysicalGoldReportLineMapper) - bukan pilihan pengguna, sbb
 * laporan sebenar tiada lajur Purity langsung utk kategori-kategori ni.
 */
class PhysicalGoldReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maklumat Laporan')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconColor('primary')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                DatePicker::make('report_date')
                                    ->label('Tarikh Laporan')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->native(false)
                                    ->default(now()),
                                DateTimePicker::make('cutoff_at')
                                    ->label('Waktu Cut-off')
                                    ->native(false),
                                TextInput::make('prepared_by')
                                    ->label('Disediakan oleh')
                                    ->default(fn () => Auth::user()?->name)
                                    ->disabledOn(['create', 'edit'])
                                    ->dehydrated(false),
                            ]),
                        Textarea::make('notes')
                            ->label('Nota')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Used Gold at HQ')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        Repeater::make('used_gold_hq_lines')
                            ->label('')
                            ->table([
                                TableColumn::make('Ketulenan'),
                                TableColumn::make('Berat (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                                TableColumn::make('Catatan')->width('10rem'),
                            ])
                            ->schema([
                                Select::make('purity_code')
                                    ->options(fn () => PhysicalGoldReportLineMapper::selectablePurities()->pluck('code', 'code'))
                                    ->native(false)
                                    ->live()
                                    ->required(),
                                TextInput::make('gross_weight')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true),
                                Placeholder::make('pure_weight_preview')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => number_format(
                                        (float) ($get('gross_weight') ?? 0) * PhysicalGoldReportLineMapper::purityFactorFor($get('purity_code')),
                                        4
                                    ).' g'),
                                TextInput::make('remarks'),
                            ])
                            ->default(fn () => PhysicalGoldReportLineMapper::defaultUsedGoldHqRows())
                            ->addActionLabel('Tambah Baris Lain (cth. 916 - YS/KIV)')
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Stock at Branch')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        Repeater::make('stock_branch_lines')
                            ->label('')
                            ->table([
                                TableColumn::make('Cawangan'),
                                TableColumn::make('Berat (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                            ])
                            ->schema([
                                Hidden::make('store_code'),
                                TextInput::make('store_label')
                                    ->label('Cawangan')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('gross_weight')
                                    ->label('Berat (g)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true),
                                Placeholder::make('pure_weight_preview')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => number_format(
                                        (float) ($get('gross_weight') ?? 0) * PhysicalGoldReportLineMapper::purityFactorFor(PhysicalGoldReportLineMapper::BLENDED_PURITY_CODE),
                                        4
                                    ).' g'),
                            ])
                            ->default(fn () => PhysicalGoldReportLineMapper::defaultBranchRows())
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Stock at HQ')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->iconColor('primary')
                    ->collapsible()
                    ->compact()
                    ->schema([
                        Repeater::make('stock_hq_lines')
                            ->label('')
                            ->table([
                                TableColumn::make('Cawangan'),
                                TableColumn::make('Berat (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                            ])
                            ->schema([
                                Hidden::make('store_code'),
                                TextInput::make('store_label')
                                    ->label('Cawangan')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('gross_weight')
                                    ->label('Berat (g)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true),
                                Placeholder::make('pure_weight_preview')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => number_format(
                                        (float) ($get('gross_weight') ?? 0) * PhysicalGoldReportLineMapper::purityFactorFor(PhysicalGoldReportLineMapper::BLENDED_PURITY_CODE),
                                        4
                                    ).' g'),
                            ])
                            ->default(fn () => PhysicalGoldReportLineMapper::defaultStockHqRows())
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('New Stock Not Yet Key-in')
                    ->icon(Heroicon::OutlinedClock)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        Repeater::make('new_stock_lines')
                            ->label('')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Select::make('vendor_code')
                                            ->label('Supplier')
                                            ->options(fn () => Vendor::query()
                                                ->where('VendorCode', '!=', '.')
                                                ->get()
                                                ->mapWithKeys(fn ($v) => [$v->VendorCode => "{$v->VendorCode} - {$v->Description}"]))
                                            ->searchable()
                                            ->required(),
                                        TextInput::make('gross_weight')
                                            ->label('Berat (g)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                    ]),
                            ])
                            ->addActionLabel('Tambah Supplier')
                            ->reorderable(false)
                            ->default([])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('GDN Not Yet Received / Not Weighed')
                    ->icon(Heroicon::OutlinedSquaresPlus)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        Repeater::make('gdn_pending_lines')
                            ->label('')
                            ->table([
                                TableColumn::make('Ketulenan'),
                                TableColumn::make('Berat (g)'),
                                TableColumn::make('Berat Tulen (g)'),
                                TableColumn::make('Dari'),
                                TableColumn::make('Hingga'),
                                TableColumn::make('Catatan')->width('10rem'),
                            ])
                            ->schema([
                                Select::make('purity_code')
                                    ->options(fn () => PhysicalGoldReportLineMapper::selectablePurities()->pluck('code', 'code'))
                                    ->native(false)
                                    ->live()
                                    ->required(),
                                TextInput::make('gross_weight')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true),
                                Placeholder::make('pure_weight_preview')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => number_format(
                                        (float) ($get('gross_weight') ?? 0) * PhysicalGoldReportLineMapper::purityFactorFor($get('purity_code')),
                                        4
                                    ).' g'),
                                DatePicker::make('date_range_from')
                                    ->native(false),
                                DatePicker::make('date_range_to')
                                    ->native(false),
                                TextInput::make('remarks'),
                            ])
                            ->default(fn () => PhysicalGoldReportLineMapper::defaultGdnRows())
                            ->addActionLabel('Tambah Baris Lain')
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Outstanding Gold Due to Suppliers')
                    ->icon(Heroicon::OutlinedBriefcase)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        Repeater::make('supplier_outstanding_lines')
                            ->label('')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        Select::make('vendor_code')
                                            ->label('Supplier')
                                            ->options(fn () => Vendor::query()
                                                ->where('VendorCode', '!=', '.')
                                                ->get()
                                                ->mapWithKeys(fn ($v) => [$v->VendorCode => "{$v->VendorCode} - {$v->Description}"]))
                                            ->searchable()
                                            ->required(),
                                        TextInput::make('payable_gross_weight')
                                            ->label('Payable (g) - kita berhutang')
                                            ->numeric(),
                                        TextInput::make('receivable_gross_weight')
                                            ->label('Receivable (g) - dihutang kpd kita')
                                            ->numeric(),
                                    ]),
                            ])
                            ->addActionLabel('Tambah Supplier')
                            ->reorderable(false)
                            ->default([])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
