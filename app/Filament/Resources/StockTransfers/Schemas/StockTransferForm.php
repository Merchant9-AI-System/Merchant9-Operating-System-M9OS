<?php

namespace App\Filament\Resources\StockTransfers\Schemas;

use App\Models\Jemisys\Store;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class StockTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextInput::make('internal_code')
                            ->label('Kod Design')
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('item_desc')
                            ->label('Jenis Item')
                            ->disabledOn('edit'),
                        TextInput::make('qty')
                            ->label('Kuantiti')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('from_store')
                            ->label('Daripada Cawangan')
                            ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode'))
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('to_store')
                            ->label('Ke Cawangan')
                            ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode'))
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('requested_by')
                            ->label('Diminta oleh')
                            ->default(fn () => Auth::user()?->name)
                            ->required()
                            ->disabledOn('edit'),
                    ]),
                Textarea::make('notes')
                    ->label('Nota')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
