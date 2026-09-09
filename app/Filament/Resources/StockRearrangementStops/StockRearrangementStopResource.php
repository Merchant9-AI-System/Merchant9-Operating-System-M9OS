<?php

namespace App\Filament\Resources\StockRearrangementStops;

use App\Filament\Resources\StockRearrangementStops\Pages\ListStockRearrangementStops;
use App\Filament\Resources\StockRearrangementStops\Tables\StockRearrangementStopsTable;
use App\Models\StockRearrangementStop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StockRearrangementStopResource extends Resource
{
    protected static ?string $model = StockRearrangementStop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'Stop Rearrange/Request';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'internal_code';

    public static function table(Table $table): Table
    {
        return StockRearrangementStopsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockRearrangementStops::route('/'),
        ];
    }
}
