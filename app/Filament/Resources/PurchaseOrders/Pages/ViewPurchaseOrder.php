<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrder::STATUS_DRAFT),

            Action::make('receiveGoods')
                ->label('Terima Barang')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->visible(fn (PurchaseOrder $record) => in_array($record->status, [
                    PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                ], true))
                ->schema(function (PurchaseOrder $record) {
                    $outstanding = $record->lines->filter(fn ($l) => $l->qty_outstanding > 0);

                    return [
                        Section::make('Kuantiti Diterima Sekarang')
                            ->description('Kosongkan/set 0 utk item yang belum sampai lagi.')
                            ->schema(
                                $outstanding->map(fn ($line) => Grid::make(4)->schema([
                                    TextInput::make("qty_{$line->id}")
                                        ->label("{$line->internal_code} - {$line->item_desc} (baki: {$line->qty_outstanding})")
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue($line->qty_outstanding)
                                        ->default(0)
                                        ->columnSpanFull(),
                                ]))->all()
                            ),
                        TextInput::make('grn_notes')->label('Nota (pilihan)'),
                    ];
                })
                ->action(function (array $data, PurchaseOrder $record) {
                    $outstanding = $record->lines->filter(fn ($l) => $l->qty_outstanding > 0);
                    $receipts = [];
                    foreach ($outstanding as $line) {
                        $qty = (int) ($data["qty_{$line->id}"] ?? 0);
                        if ($qty > 0) {
                            $receipts[$line->id] = $qty;
                        }
                    }

                    if (empty($receipts)) {
                        Notification::make()->title('Tiada kuantiti dimasukkan.')->warning()->send();

                        return;
                    }

                    $grn = GoodsReceipt::receive($record, $receipts, Auth::user()->name, $data['grn_notes'] ?? null);
                    Notification::make()->title("GRN {$grn->grn_number} disimpan - PO status: {$record->fresh()->status}")->success()->send();
                }),
        ];
    }
}
