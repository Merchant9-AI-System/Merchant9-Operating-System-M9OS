<?php

namespace App\Filament\Resources\StockTransfers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockTransferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maklumat Transfer')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('transfer_number')->label('No. Transfer'),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('internal_code')->label('Kod Design'),
                                TextEntry::make('item_desc')->label('Jenis Item'),
                                TextEntry::make('from_store')->label('Dari Cawangan'),
                                TextEntry::make('to_store')->label('Ke Cawangan'),
                                TextEntry::make('qty')->label('Kuantiti'),
                                TextEntry::make('requested_by')->label('Diminta oleh'),
                                TextEntry::make('requested_at')->label('Tarikh Minta')->dateTime('d/m/Y H:i'),
                                TextEntry::make('in_transit_at')->label('Tarikh In Transit')->dateTime('d/m/Y H:i')->placeholder('-'),
                                TextEntry::make('received_by')->label('Diterima oleh')->placeholder('-'),
                                TextEntry::make('received_at')->label('Tarikh Diterima')->dateTime('d/m/Y H:i')->placeholder('-'),
                            ]),
                        TextEntry::make('notes')->label('Nota')->placeholder('-')->columnSpanFull(),
                    ]),
            ]);
    }
}
