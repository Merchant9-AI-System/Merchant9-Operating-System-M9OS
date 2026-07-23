<?php

namespace App\Filament\Resources\DailyAssetPositions\Schemas;

use App\Models\DailyAssetPosition;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Borang key-in harian accountant. Medan "computed" (Total Stock In/Out, Net Weight, Available
 * Cash) dipaparkan sbg Placeholder LIVE sahaja (rujukan visual) - nilai SEBENAR yg disimpan
 * sentiasa dikira semula di App\Models\DailyAssetPosition::booted() (saving()), bukan dipercayai
 * drpd state borang. closing_stock pula medan input SEBENAR (dikeyin accountant) - dibanding vs
 * pengiraan formula utk amaran mismatch (rujuk arahan asal §Validation).
 */
class DailyAssetPositionForm
{
    private const STOCK_IN_FIELDS = ['new_stock', 'used_gold', 'gold_bar', 'unpaid_unreceived_bar', 'paid_unreceived_bar', 'loan_received'];

    private const STOCK_OUT_FIELDS = ['sales', 'payment_to_supplier', 'stock_out_return', 'loss_from_melting', 'loan_out'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tarikh & Stok Pembukaan')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('entry_date')
                                ->label('Tarikh')
                                ->required()
                                ->live()
                                ->unique(ignoreRecord: true)
                                // ->min()
                                ->default(now())
                                ->afterStateUpdated(function (Get $get, $set) {
                                    // Auto isi opening stock drpd closing stock rekod terdekat sebelum tarikh ni,
                                    // supaya accountant tak perlu cari sendiri (boleh diubah manual - amaran je bila beza).
                                    if (blank($get('opening_stock_weight'))) {
                                        $set('opening_stock_weight', DailyAssetPosition::closingStockBefore($get('entry_date')) ?? 0);
                                    }
                                }),
                            TextInput::make('opening_stock_weight')
                                ->label('Opening Stock Weight (g)')
                                ->numeric()
                                ->required()
                                ->live(onBlur: true)
                                ->placeholder(fn () => DailyAssetPosition::closingStockBefore(null) ?? 0),
                        ]),
                        Placeholder::make('opening_stock_mismatch_warning')
                            ->label('')
                            ->live()
                            ->content(function (Get $get) {
                                $previous = DailyAssetPosition::closingStockBefore($get('entry_date'));
                                if ($previous === null || abs((float) ($get('opening_stock_weight') ?? 0) - $previous) < 0.005) {
                                    return null;
                                }

                                return new HtmlString(
                                    '<span class="text-sm text-warning-600 font-medium">⚠️ Opening stock ('.
                                    number_format((float) $get('opening_stock_weight'), 3).
                                    'g) tak sepadan dgn closing stock rekod sebelumnya ('.
                                    number_format($previous, 3).'g). Sila isi Notes utk terangkan sebab.</span>'
                                );
                            })
                            ->visible(fn (Get $get) => $get('opening_stock_weight') !== null),
                    ])
                    ->columns(1)
                    ->columnSpan('full'),

                Section::make('Stok Masuk (Stock In)')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('new_stock')->label('New Stock (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('used_gold')->label('Used Gold (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('gold_bar')->label('Gold Bar (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('unpaid_unreceived_bar')->label('Unpaid Unreceived Bar (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('paid_unreceived_bar')->label('Paid Unreceived Bar (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('loan_received')->label('Loan Received (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                        ]),
                        Placeholder::make('total_stock_in_preview')
                            ->label('Total Stock In (auto)')
                            ->live()
                            ->content(fn (Get $get) => number_format(static::sum($get, self::STOCK_IN_FIELDS), 2).' g'),
                    ]),

                Section::make('Stok Keluar (Stock Out)')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('sales')->label('Sales (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('payment_to_supplier')->label('Payment To Supplier (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('stock_out_return')->label('Stock Out / Return (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('loss_from_melting')->label('Loss From Melting (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('loan_out')->label('Loan Out (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                        ]),
                        Placeholder::make('total_stock_out_preview')
                            ->label('Total Stock Out (auto)')
                            ->live()
                            ->content(fn (Get $get) => number_format(static::sum($get, self::STOCK_OUT_FIELDS), 2).' g'),
                    ]),

                Section::make('Tunai & Bank (RM)')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('ambank_balance')->label('Ambank Balance (RM)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('affin_balance')->label('Affin Balance (RM)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('cash')->label('Cash (RM)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('affin_rm')->label('Affin FD RM')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('od_affin')->label('Affin OD (RM)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('locked_gold_bar')->label('Unpaid Gold Bar RM')->numeric()->placeholder(0)->required(),
                        ]),
                        Placeholder::make('available_cash_preview')
                            ->label('Available Cash (auto = Amb + afb balance + cash - afb FD - OD - unpaid)') // Ambank Balance + Affin Balance + Cash - Affin FD - OD - Unpaid Gold Bar
                            ->live()
                            ->content(fn (Get $get) => 'RM '.number_format(static::availableCash($get), 2)),
                        Placeholder::make('available_cash_for_gb_preview')
                            ->label('Cash For GB (auto = Available Cash - RM600k - RM1,000,000)')
                            ->live()
                            ->content(fn (Get $get) => 'RM '.number_format(static::cashForGb($get), 2)),
                    ]),

                Section::make('Stok Penutup & Baki Bersih')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('closing_stock')
                                ->label('Closing Stock (g) - dikeyin')
                                ->numeric()
                                ->required()
                                ->live(onBlur: true),
                            TextInput::make('supplier_hutang')->label('Supplier Hutang (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                            TextInput::make('supplier_overpaid')->label('Supplier Overpaid (g)')->numeric()->placeholder(0)->required()->live(onBlur: true),
                        ]),
                        Placeholder::make('closing_stock_computed_preview')
                            ->label('Closing Stock ikut formula (Opening + In - Out)')
                            ->live()
                            ->content(function (Get $get) {
                                $computed = (float) ($get('opening_stock_weight') ?? 0)
                                    + static::sum($get, self::STOCK_IN_FIELDS)
                                    - static::sum($get, self::STOCK_OUT_FIELDS);

                                return number_format($computed, 2).' g';
                            }),
                        Placeholder::make('closing_stock_mismatch_warning')
                            ->label('')
                            ->live()
                            ->content(function (Get $get) {
                                $computed = (float) ($get('opening_stock_weight') ?? 0)
                                    + static::sum($get, self::STOCK_IN_FIELDS)
                                    - static::sum($get, self::STOCK_OUT_FIELDS);
                                $keyed = (float) ($get('closing_stock') ?? 0);

                                if (abs($keyed - $computed) < 0.005) {
                                    return null;
                                }

                                return new HtmlString(
                                    '<span class="text-sm text-warning-600 font-medium">⚠️ Closing stock yg dikeyin ('.
                                    number_format($keyed, 2).'g) tak sepadan dgn pengiraan formula ('.
                                    number_format($computed, 2).'g). Sila isi Notes utk terangkan sebab (cth. timbang semula fizikal).</span>'
                                );
                            }),
                        Placeholder::make('net_weight_preview')
                            ->label('Net Weight (auto = Closing - Hutang + Overpaid)')
                            ->live()
                            ->content(function (Get $get) {
                                $net = (float) ($get('closing_stock') ?? 0)
                                    - (float) ($get('supplier_hutang') ?? 0)
                                    + (float) ($get('supplier_overpaid') ?? 0);

                                return number_format($net, 2).' g';
                            }),
                    ]),

                Section::make('Catatan')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes / Remarks')
                            ->rows(3)
                            ->required(fn (Get $get) => static::hasMismatch($get))
                            ->helperText('Wajib diisi jika ada amaran mismatch di atas.'),
                        TextInput::make('created_by')
                            ->label('Created By')
                            ->readOnly()
                            ->default(fn () => Auth::user()?->name)
                            ->required()
                            ->disabledOn('edit')
                            ->dehydrated(),
                    ])
                    ->columnSpan('full'),
            ]);
    }

    private static function sum(Get $get, array $fields): float
    {
        return array_sum(array_map(fn ($f) => (float) ($get($f) ?? 0), $fields));
    }

    /**
     * Dikongsi antara 'available_cash_preview' & 'available_cash_for_gb_preview' - JANGAN cuba
     * $get() nilai Placeholder lain (cth. $get('available_cash_preview')), Placeholder tak
     * daftar apa-apa dlm state borang (cuma paparan, bukan medan input) - $get() atas nama
     * Placeholder sentiasa pulangkan null/0 senyap (punca bug asal "Cash For GB" - formula
     * runtuh jadi 0 - od_affin - cash tanpa ralat). Sepadan App\Models\DailyAssetPosition::
     * calculateAvailableCash() - KEKALKAN kedua-duanya selari bila formula berubah.
     */
    private static function availableCash(Get $get): float
    {
        return (float) ($get('ambank_balance') ?? 0)
            + (float) ($get('affin_balance') ?? 0)
            + (float) ($get('cash') ?? 0)
            - (float) ($get('affin_rm') ?? 0)
            - (float) ($get('od_affin') ?? 0)
            - (float) ($get('locked_gold_bar') ?? 0);
    }

    /** Sepadan App\Models\DailyAssetPosition::calculateCashForGb() - rizab dikongsi drpd konstanta model. */
    private static function cashForGb(Get $get): float
    {
        return round(
            static::availableCash($get)
            - DailyAssetPosition::CASH_FOR_GB_RESERVE_WORKING_CAPITAL
            - DailyAssetPosition::CASH_FOR_GB_RESERVE_FIXED,
            2
        );
    }

    private static function hasMismatch(Get $get): bool
    {
        $previous = DailyAssetPosition::closingStockBefore($get('entry_date'));
        $openingMismatch = $previous !== null
            && abs((float) ($get('opening_stock_weight') ?? 0) - $previous) >= 0.005;

        $computedClosing = (float) ($get('opening_stock_weight') ?? 0)
            + static::sum($get, self::STOCK_IN_FIELDS)
            - static::sum($get, self::STOCK_OUT_FIELDS);
        $closingMismatch = abs((float) ($get('closing_stock') ?? 0) - $computedClosing) >= 0.005;

        return $openingMismatch || $closingMismatch;
    }
}
