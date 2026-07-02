<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\PurchaseOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maklumat PO')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('po_number')->label('No. PO'),
                                TextEntry::make('vendor_name')->label('Supplier'),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('expected_delivery_date')->label('Jangkaan Terima')->date('d/m/Y'),
                                TextEntry::make('created_by')->label('Dicipta oleh'),
                                TextEntry::make('created_at')->label('Tarikh Cipta')->dateTime('d/m/Y H:i'),
                                TextEntry::make('approved_by')->label('Diluluskan oleh')->placeholder('-'),
                                TextEntry::make('approved_at')->label('Tarikh Lulus')->dateTime('d/m/Y H:i')->placeholder('-'),
                            ]),
                        TextEntry::make('notes')->label('Nota')->placeholder('-')->columnSpanFull(),
                    ]),

                Section::make('Item Order')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(6)
                                    ->schema([
                                        TextEntry::make('internal_code')->label('Kod Design'),
                                        TextEntry::make('item_desc')->label('Jenis Item'),
                                        TextEntry::make('qty_ordered')->label('Kuantiti')->numeric(),
                                        TextEntry::make('unit_cost')->label('Kos/Unit')->money('MYR'),
                                        TextEntry::make('qty_received')->label('Diterima')->numeric()->badge()
                                            ->color(fn ($record) => $record->qty_received >= $record->qty_ordered ? 'success' : ($record->qty_received > 0 ? 'warning' : 'gray')),
                                        TextEntry::make('subtotal')->label('Subjumlah')->money('MYR'),
                                    ]),
                            ]),
                        TextEntry::make('total_amount')
                            ->label('JUMLAH KESELURUHAN')
                            ->money('MYR')
                            ->weight('bold')
                            ->size('lg'),
                    ]),

                Section::make('Sejarah Penerimaan (GRN)')
                    ->schema([
                        RepeatableEntry::make('goodsReceipts')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        TextEntry::make('grn_number')->label('No. GRN'),
                                        TextEntry::make('received_by')->label('Diterima oleh'),
                                        TextEntry::make('received_at')->label('Tarikh')->dateTime('d/m/Y H:i'),
                                        TextEntry::make('notes')->label('Nota')->placeholder('-'),
                                    ]),
                            ]),
                    ])
                    ->visible(fn (PurchaseOrder $record) => $record->goodsReceipts->isNotEmpty()),
            ]);
    }
}
