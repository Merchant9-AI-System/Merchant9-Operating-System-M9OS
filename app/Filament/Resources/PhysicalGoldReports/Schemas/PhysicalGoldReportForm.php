<?php

namespace App\Filament\Resources\PhysicalGoldReports\Schemas;

use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use App\Models\PhysicalGoldCategory;
use App\Models\PhysicalGoldPurity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class PhysicalGoldReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maklumat Laporan')
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

                Section::make('Butiran Emas Fizikal')
                    ->schema([
                        Repeater::make('lines')
                            ->label('')
                            ->relationship()
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Select::make('physical_gold_category_id')
                                            ->label('Kategori')
                                            ->options(fn () => PhysicalGoldCategory::query()
                                                ->where('active', true)
                                                ->orderBy('sort_order')
                                                ->pluck('name', 'id'))
                                            ->live()
                                            ->searchable()
                                            ->required()
                                            ->columnSpan(2),
                                        Select::make('store_code')
                                            ->label('Cawangan')
                                            ->options(fn () => Store::query()
                                                ->where('Active', 1)
                                                ->where('StoreCode', '!=', 'SECURITY')
                                                ->pluck('StoreCode', 'StoreCode'))
                                            ->searchable()
                                            ->visible(fn (Get $get) => static::categoryRequires($get, 'requires_branch'))
                                            ->required(fn (Get $get) => static::categoryRequires($get, 'requires_branch')),
                                        Select::make('vendor_code')
                                            ->label('Supplier')
                                            ->options(fn () => Vendor::query()
                                                ->where('VendorCode', '!=', '.')
                                                ->get()
                                                ->mapWithKeys(fn ($v) => [$v->VendorCode => "{$v->VendorCode} - {$v->Description}"]))
                                            ->searchable()
                                            ->visible(fn (Get $get) => static::categoryRequires($get, 'requires_supplier'))
                                            ->required(fn (Get $get) => static::categoryRequires($get, 'requires_supplier')),
                                        Select::make('physical_gold_purity_id')
                                            ->label('Ketulenan')
                                            ->options(fn () => PhysicalGoldPurity::query()
                                                ->where('active', true)
                                                ->orderBy('sort_order')
                                                ->pluck('code', 'id'))
                                            ->native(false)
                                            ->visible(fn (Get $get) => static::categoryRequires($get, 'requires_purity'))
                                            ->required(fn (Get $get) => static::categoryRequires($get, 'requires_purity')),
                                        DatePicker::make('date_range_from')
                                            ->label('Dari')
                                            ->native(false)
                                            ->visible(fn (Get $get) => static::categoryRequires($get, 'requires_date_range')),
                                        DatePicker::make('date_range_to')
                                            ->label('Hingga')
                                            ->native(false)
                                            ->visible(fn (Get $get) => static::categoryRequires($get, 'requires_date_range')),
                                        TextInput::make('remarks')
                                            ->label('Catatan (cth. YS, KIV)'),
                                        TextInput::make('gross_weight')
                                            ->label('Berat Kasar (g)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->visible(fn (Get $get) => static::categoryValueMode($get) === PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY)
                                            ->required(fn (Get $get) => static::categoryValueMode($get) === PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY),
                                        TextInput::make('payable_pure_weight')
                                            ->label('Payable Tulen (g)')
                                            ->numeric()
                                            ->visible(fn (Get $get) => static::categoryValueMode($get) === PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE),
                                        TextInput::make('receivable_pure_weight')
                                            ->label('Receivable Tulen (g)')
                                            ->numeric()
                                            ->visible(fn (Get $get) => static::categoryValueMode($get) === PhysicalGoldCategory::VALUE_MODE_PAYABLE_RECEIVABLE),
                                    ]),
                            ])
                            ->addActionLabel('Tambah Baris')
                            ->reorderable(false)
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Dikongsi antara semua closure visible()/required() Repeater - satu lookup memoized per
     * request (bukan setiap panggilan) supaya tak N+1 setiap baris/setiap render semasa live().
     */
    protected static function categories()
    {
        static $categories = null;

        return $categories ??= PhysicalGoldCategory::query()->get()->keyBy('id');
    }

    protected static function categoryRequires(Get $get, string $flag): bool
    {
        $category = static::categories()->get($get('physical_gold_category_id'));

        return (bool) ($category?->{$flag} ?? false);
    }

    protected static function categoryValueMode(Get $get): ?string
    {
        return static::categories()->get($get('physical_gold_category_id'))?->value_mode;
    }
}
