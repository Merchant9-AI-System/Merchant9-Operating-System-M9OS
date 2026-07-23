<?php

namespace App\Filament\Resources\DailyAssetPositions\Schemas;

use App\Models\DailyAssetPosition;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DailyAssetPositionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('entry_date')->label('Tarikh')->date('d/m/Y'),
                            TextEntry::make('opening_stock_weight')->label('Opening Stock')->numeric(2)->suffix(' g'),
                            TextEntry::make('closing_stock')->label('Closing Stock')->numeric(2)->suffix(' g'),
                            TextEntry::make('net_weight')->label('Net Weight')->numeric(2)->suffix(' g'),
                            TextEntry::make('total_stock_in')->label('Total Stock In')->numeric(2)->suffix(' g')->color('success'),
                            TextEntry::make('total_stock_out')->label('Total Stock Out')->numeric(2)->suffix(' g')->color('danger'),
                            TextEntry::make('available_cash')->label('Available Cash')->money('MYR'),
                            TextEntry::make('cash_for_gb')->label('Cash For GB')->money('MYR'),
                            TextEntry::make('mismatch')
                                ->label('Status')
                                ->state(fn (DailyAssetPosition $r) => $r->hasAnyMismatch() ? 'Ada Mismatch' : 'Sepadan')
                                ->badge()
                                ->color(fn (DailyAssetPosition $r) => $r->hasAnyMismatch() ? 'warning' : 'success'),
                        ]),
                    ]),

                Section::make('Stok Masuk / Keluar')
                    ->schema([
                        Grid::make(5)->schema([
                            TextEntry::make('new_stock')->label('New Stock')->numeric(2)->suffix(' g'),
                            TextEntry::make('used_gold')->label('Used Gold')->numeric(2)->suffix(' g'),
                            TextEntry::make('gold_bar')->label('Gold Bar')->numeric(2)->suffix(' g'),
                            TextEntry::make('unpaid_unreceived_bar')->label('Unpaid Unreceived Bar')->numeric(2)->suffix(' g'),
                            TextEntry::make('paid_unreceived_bar')->label('Paid Unreceived Bar')->numeric(2)->suffix(' g'),
                            TextEntry::make('loan_received')->label('Loan Received')->numeric(2)->suffix(' g'),
                            TextEntry::make('sales')->label('Sales')->numeric(2)->suffix(' g'),
                            TextEntry::make('payment_to_supplier')->label('Payment To Supplier')->numeric(2)->suffix(' g'),
                            TextEntry::make('stock_out_return')->label('Stock Out / Return')->numeric(2)->suffix(' g'),
                            TextEntry::make('loss_from_melting')->label('Loss From Melting')->numeric(2)->suffix(' g'),
                            TextEntry::make('loan_out')->label('Loan Out')->numeric(2)->suffix(' g'),
                        ]),
                    ]),

                Section::make('Supplier & Tunai/Bank')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('supplier_hutang')->label('Supplier Hutang')->numeric(2)->suffix(' g'),
                            TextEntry::make('supplier_overpaid')->label('Supplier Overpaid')->numeric(2)->suffix(' g'),
                            TextEntry::make('locked_gold_bar')->label('Locked Gold Bar')->numeric(2)->suffix(' g'),
                            TextEntry::make('ambank_balance')->label('Ambank Balance')->money('MYR'),
                            TextEntry::make('affin_balance')->label('Affin Balance')->money('MYR'),
                            TextEntry::make('cash')->label('Cash')->money('MYR'),
                            TextEntry::make('affin_rm')->label('Affin RM')->money('MYR'),
                            TextEntry::make('od_affin')->label('OD Affin')->money('MYR'),
                        ]),
                    ]),

                Section::make('Catatan & Rekod')
                    ->schema([
                        TextEntry::make('notes')->label('Notes')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('created_by')->label('Created By'),
                        TextEntry::make('updated_by')->label('Updated By')->placeholder('-'),
                    ])
                    ->columns(2),

                Section::make('Jejak Audit')
                    ->schema([
                        RepeatableEntry::make('audits')
                            ->label('')
                            ->schema([
                                TextEntry::make('created_at')->label('Bila')->dateTime('d/m/Y H:i'),
                                TextEntry::make('actor')->label('Oleh'),
                                TextEntry::make('action')->label('Tindakan')->badge(),
                            ])
                            ->columns(3),
                    ])
                    ->collapsed(),
            ]);
    }
}
