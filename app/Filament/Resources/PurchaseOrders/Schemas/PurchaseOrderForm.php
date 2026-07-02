<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\Jemisys\Vendor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maklumat PO')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Select::make('vendor_code')
                                    ->label('Supplier')
                                    ->options(fn () => Vendor::where('VendorCode', '!=', '.')
                                        ->get()
                                        ->mapWithKeys(fn ($v) => [$v->VendorCode => "{$v->VendorCode} - {$v->Description}"]))
                                    ->searchable()
                                    ->required()
                                    ->disabledOn('edit'),
                                DatePicker::make('expected_delivery_date')
                                    ->label('Jangkaan Tarikh Terima')
                                    ->native(false),
                                TextInput::make('created_by')
                                    ->label('Dicipta oleh')
                                    ->default(fn () => Auth::user()?->name)
                                    ->required()
                                    ->disabledOn('edit'),
                            ]),
                        Textarea::make('notes')
                            ->label('Nota')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Item Order')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        TextInput::make('internal_code')
                                            ->label('Kod Design')
                                            ->required(),
                                        TextInput::make('item_desc')
                                            ->label('Jenis Item'),
                                        TextInput::make('qty_ordered')
                                            ->label('Kuantiti')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(),
                                        TextInput::make('unit_cost')
                                            ->label('Kos Seunit (RM)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                    ]),
                            ])
                            ->addActionLabel('+ Tambah Item')
                            ->reorderable(false)
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
