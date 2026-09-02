<?php

namespace App\Filament\Resources\PhysicalGoldReports\Schemas;

use App\Models\Jemisys\Vendor;
use App\Support\PhysicalGoldReportLineMapper;
use App\Support\UsedGoldBalanceProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Borang disusun ikut seksyen TETAP sepadan Weekly Stock Report sebenar - TIADA pilihan
 * kategori manual (setiap seksyen sudah tetap kategori dia). Ketulenan sudah "stated" (satu
 * baris pra-isi per ketulenan aktif, sama struktur) utk Used Gold at HQ, GDN, Stock at HQ, DAN
 * Stock at Branch (nested per cawangan - atas permintaan eksplisit, GANTI pendekatan "blended"
 * 930 lama). Stock at Branch pra-isi cawangan (semua cawangan aktif kecuali HQ/SECURITY), tiap
 * satu ada nested repeater pecahan ketulenan sendiri. New Stock & Outstanding kekal repeater
 * bebas (supplier pelbagai), guna faktor ketulenan "blended" 930 automatik (rujuk
 * App\Support\PhysicalGoldReportLineMapper) - bukan pilihan pengguna, sbb laporan sebenar tiada
 * lajur Purity langsung utk kategori-kategori ni.
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
                        // Tarik drpd sistem Used Gold LUARAN (merchant9.kedaiemas.my, rujuk
                        // App\Support\UsedGoldBalanceProvider) - eksplisit atas permintaan
                        // pengguna, BUKAN default senyap (endpoint luar boleh lambat/turun,
                        // jangan block create/edit laporan). Hanya baris ASAS (remarks kosong)
                        // ditimpa - baris variant (cth. "916 - YS") kekal x disentuh, endpoint
                        // luar tiada konsep sub-variant tsb.
                        Actions::make([
                            Action::make('pullFromUsedGoldBalance')
                                ->label('Tarik Data Used Gold')
                                ->icon(Heroicon::OutlinedArrowDownTray)
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalDescription('Ganti nilai Berat (g) baris ASAS sedia ada di Used Gold at HQ dgn baki closing bulan semasa drpd sistem Used Gold luaran. Nilai yg dah ditaip akan HILANG (baris variant cth. "916 - YS" x disentuh).')
                                ->action(function (Set $set, Get $get) {
                                    $closing = UsedGoldBalanceProvider::closingByPurity();

                                    if ($closing === null) {
                                        Notification::make()
                                            ->title('Gagal tarik data - sistem Used Gold luaran tak dpt dicapai.')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    $rows = collect($get('used_gold_hq_lines'))
                                        ->map(function (array $row) use ($closing) {
                                            if (blank($row['remarks'] ?? null) && array_key_exists($row['purity_code'], $closing)) {
                                                $row['gross_weight'] = $closing[$row['purity_code']];
                                            }

                                            return $row;
                                        })
                                        ->all();

                                    $set('used_gold_hq_lines', $rows);

                                    Notification::make()
                                        ->title('Data Used Gold ditarik - sila semak sebelum simpan.')
                                        ->success()
                                        ->send();
                                }),
                        ]),
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
                                        2
                                    ).' g'),
                                TextInput::make('remarks'),
                            ])
                            ->default(fn () => PhysicalGoldReportLineMapper::defaultUsedGoldHqRows())
                            ->addActionLabel('Tambah Baris Lain (cth. 916 - YS/KIV)')
                            ->reorderable(false)
                            ->columnSpanFull(),
                        static::purityFooter('used_gold_hq_lines'),
                    ])
                    ->columnSpanFull(),

                Section::make('Stock at Branch')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        // Tarik SEKALI utk Stock at Branch & Stock at HQ (satu query, dua
                        // seksyen) - eksplisit atas permintaan pengguna (bukan default senyap
                        // supaya x hilang data yg dah ditaip tanpa disedari), rujuk
                        // PhysicalGoldReportLineMapper::inventoryStockByStoreAndPurity().
                        Actions::make([
                            Action::make('pullFromInventory')
                                ->label('Tarik Data Inventori (Stock at Branch & HQ)')
                                ->icon(Heroicon::OutlinedArrowDownTray)
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalDescription('Ganti SEMUA nilai Berat (g) sedia ada di Stock at Branch & Stock at HQ dgn stok fizikal semasa (jemisys_inventory_mirror). Nilai yg dah ditaip akan HILANG.')
                                ->action(function (Set $set, Get $get) {
                                    $stock = PhysicalGoldReportLineMapper::inventoryStockByStoreAndPurity();

                                    $branchRows = collect($get('stock_branch_lines'))->map(function (array $branch) use ($stock) {
                                        $storeStock = $stock->get(trim((string) $branch['store_code']), collect());

                                        $branch['purity_lines'] = collect($branch['purity_lines'])
                                            ->map(fn (array $row) => [...$row, 'gross_weight' => $storeStock->get($row['purity_code']) ?? 0.0])
                                            ->all();

                                        return $branch;
                                    })->all();

                                    $set('stock_branch_lines', $branchRows);

                                    $hqStock = $stock->get('HQ', collect());

                                    $hqRows = collect($get('stock_hq_lines'))
                                        ->map(fn (array $row) => [...$row, 'gross_weight' => $hqStock->get($row['purity_code']) ?? 0.0])
                                        ->all();

                                    $set('stock_hq_lines', $hqRows);

                                    Notification::make()
                                        ->title('Data inventori ditarik - sila semak sebelum simpan.')
                                        ->success()
                                        ->send();
                                }),
                        ]),
                        // Satu blok per cawangan (tetap - addable/deletable false, sepadan
                        // tingkah laku asal), TIAP blok ada nested repeater "purity_lines"
                        // pecahan ketulenan sendiri (sepadan Used Gold at HQ) - atas permintaan
                        // eksplisit "stock at branch tunjuk ikut cawangan JUGA ikut ketulenan".
                        Repeater::make('stock_branch_lines')
                            ->label('')
                            ->schema([
                                Hidden::make('store_code'),
                                TextInput::make('store_label')
                                    ->label('Cawangan')
                                    ->disabled()
                                    ->dehydrated(false),
                                Repeater::make('purity_lines')
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
                                                2
                                            ).' g'),
                                        TextInput::make('remarks'),
                                    ])
                                    ->addActionLabel('Tambah Ketulenan Lain')
                                    ->reorderable(false)
                                    ->columnSpanFull(),
                                static::purityFooter('purity_lines'),
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
                        // HQ SATU lokasi sahaja (tiada dimensi cawangan) - pecahan ketulenan
                        // terus, struktur SAMA dgn Used Gold at HQ atas permintaan eksplisit
                        // "stock at hq tunjuk ikut ketulenan".
                        Repeater::make('stock_hq_lines')
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
                                        2
                                    ).' g'),
                                TextInput::make('remarks'),
                            ])
                            ->default(fn () => PhysicalGoldReportLineMapper::defaultStockHqRows())
                            ->addActionLabel('Tambah Baris Lain')
                            ->reorderable(false)
                            ->columnSpanFull(),
                        static::purityFooter('stock_hq_lines'),
                    ])
                    ->columnSpanFull(),

                Section::make('New Stock Not Yet Key-in')
                    ->icon(Heroicon::OutlinedClock)
                    ->iconColor('primary')
                    ->collapsible()
                    ->schema([
                        Repeater::make('new_stock_lines')
                            ->label('')
                            ->table([
                                TableColumn::make('Supplier'),
                                TableColumn::make('Berat (g)'),
                                TableColumn::make('Workmanship (RM)'),
                                TableColumn::make('Gold Price (RM/g)'),
                                TableColumn::make('Gold Amount (RM)'),
                                TableColumn::make('Total Price (RM)'),
                            ])
                            ->schema([
                                Select::make('vendor_code')
                                    ->options(fn () => Vendor::query()
                                        ->where('VendorCode', '!=', '.')
                                        ->get()
                                        ->mapWithKeys(fn ($v) => [$v->VendorCode => "{$v->VendorCode} - {$v->Description}"]))
                                    ->searchable()
                                    ->required(),
                                TextInput::make('gross_weight')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true)
                                    ->required(),
                                TextInput::make('workmanship_amount')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true),
                                TextInput::make('gold_price_per_gram')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true),
                                Placeholder::make('gold_amount_preview')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => 'RM '.number_format(
                                        (float) ($get('gross_weight') ?? 0) * (float) ($get('gold_price_per_gram') ?? 0),
                                        2
                                    )),
                                Placeholder::make('total_price_preview')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get) => 'RM '.number_format(
                                        (float) ($get('workmanship_amount') ?? 0)
                                            + ((float) ($get('gross_weight') ?? 0) * (float) ($get('gold_price_per_gram') ?? 0)),
                                        2
                                    )),
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
                                        2
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
                        static::purityFooter('gdn_pending_lines'),
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

    /**
     * Footer "Jumlah Berat / Jumlah Berat Tulen" bagi SATU repeater ketulenan (Used Gold at HQ,
     * Stock at HQ, GDN Pending, & nested purity_lines per cawangan Stock at Branch) - rujuk
     * PhysicalGoldReportLineMapper::sumPurityRows(). Placeholder biasa TANPA ->live() sendiri -
     * TextInput gross_weight repeater yg sama dah ->live(onBlur: true), setiap render semula
     * turut nilai semula content() ni (sepadan pure_weight_preview per-baris sedia ada).
     */
    protected static function purityFooter(string $fieldName): Placeholder
    {
        return Placeholder::make($fieldName.'_totals')
            ->hiddenLabel()
            ->content(function (Get $get) use ($fieldName) {
                $sums = PhysicalGoldReportLineMapper::sumPurityRows($get($fieldName));

                return new HtmlString(
                    '<div class="flex justify-end gap-6 border-t pt-2 text-sm font-semibold">'.
                    '<span>Jumlah Berat: '.number_format($sums['gross'], 2).' g</span>'.
                    '<span>Jumlah Berat Tulen: '.number_format($sums['pure'], 2).' g</span>'.
                    '</div>'
                );
            });
    }
}
