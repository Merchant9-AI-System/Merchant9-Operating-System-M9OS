<?php

namespace App\Filament\Resources\BranchDemandRequests\Schemas;

use App\Models\Jemisys\InventoryPiece;
use App\Support\ProductImageFetcher;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class BranchDemandRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item Diperlukan')
                    ->description('Cari kod design, sahkan stok semasa di cawangan anda, & masukkan kuantiti diperlukan.')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Select::make('internal_code')
                                            ->label('Kod Design')
                                            ->searchable()
                                            ->getSearchResultsUsing(fn (string $search) => InventoryPiece::query()
                                                ->where('InternalCode', 'like', "{$search}%")
                                                ->distinct()
                                                ->limit(20)
                                                ->pluck('Description', 'InternalCode')
                                                ->mapWithKeys(fn ($desc, $code) => [$code => trim($code).' - '.$desc]))
                                            ->getOptionLabelUsing(function (?string $state) {
                                                if (blank($state)) {
                                                    return null;
                                                }
                                                $piece = InventoryPiece::where('InternalCode', $state)->first();

                                                return $piece ? trim($state).' - '.$piece->Description : $state;
                                            })
                                            ->live()
                                            ->required()
                                            ->columnSpan(2),
                                        TextInput::make('qty_requested')
                                            ->label('Kuantiti')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required()
                                            ->columnSpan(1),
                                        Placeholder::make('current_stock_preview')
                                            ->label('Stok Semasa Cawangan')
                                            ->content(function (Get $get) {
                                                $code = $get('internal_code');
                                                if (blank($code)) {
                                                    return '-';
                                                }
                                                $storeCode = Auth::user()?->store_code;
                                                $stock = InventoryPiece::where('InternalCode', $code)
                                                    ->when($storeCode, fn ($q) => $q->where('StoreCode', $storeCode))
                                                    ->onHand()
                                                    ->count();

                                                return "{$stock} unit";
                                            })
                                            ->columnSpan(1),
                                        Placeholder::make('image_preview')
                                            ->hiddenLabel()
                                            ->content(function (Get $get) {
                                                $code = $get('internal_code');
                                                if (blank($code)) {
                                                    return '';
                                                }
                                                $url = ProductImageFetcher::firstImageUrlFor($code);

                                                return $url
                                                    ? new HtmlString('<img src="'.e($url).'" style="height:60px;border-radius:6px;" alt="">')
                                                    : '';
                                            })
                                            ->columnSpanFull(),
                                    ]),
                            ])
                            ->addActionLabel('+ Tambah Item')
                            ->reorderable(false)
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Section::make('Nota')
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->placeholder('Nota tambahan (pilihan)')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }
}
