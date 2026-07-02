<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('po_number')->label('No. PO')->searchable()->sortable(),
                TextColumn::make('vendor_name')->label('Supplier')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        PurchaseOrder::STATUS_DRAFT => 'gray',
                        PurchaseOrder::STATUS_PENDING_APPROVAL => 'warning',
                        PurchaseOrder::STATUS_APPROVED => 'info',
                        PurchaseOrder::STATUS_SENT => 'primary',
                        PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'warning',
                        PurchaseOrder::STATUS_RECEIVED => 'success',
                        PurchaseOrder::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_amount')
                    ->label('Jumlah (RM)')
                    ->state(fn (PurchaseOrder $record) => $record->total_amount)
                    ->money('MYR')
                    ->sortable(false),
                TextColumn::make('expected_delivery_date')->label('Jangkaan Terima')->date('d/m/Y')->sortable(),
                TextColumn::make('created_by')->label('Dicipta oleh'),
                TextColumn::make('created_at')->label('Tarikh Cipta')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    PurchaseOrder::STATUS_DRAFT => 'Draft',
                    PurchaseOrder::STATUS_PENDING_APPROVAL => 'Pending Approval',
                    PurchaseOrder::STATUS_APPROVED => 'Approved',
                    PurchaseOrder::STATUS_SENT => 'Sent',
                    PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'Partially Received',
                    PurchaseOrder::STATUS_RECEIVED => 'Received',
                    PurchaseOrder::STATUS_CANCELLED => 'Cancelled',
                ]),
                SelectFilter::make('vendor_code')
                    ->label('Supplier')
                    ->options(fn () => PurchaseOrder::query()->distinct()->pluck('vendor_name', 'vendor_code')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrder::STATUS_DRAFT),

                Action::make('submit')
                    ->label('Hantar utk Kelulusan')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrder::STATUS_DRAFT)
                    ->action(function (PurchaseOrder $record) {
                        $record->submitForApproval();
                        Notification::make()->title("PO {$record->po_number} dihantar utk kelulusan")->success()->send();
                    }),

                Action::make('approve')
                    ->label('Luluskan')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrder::STATUS_PENDING_APPROVAL
                        && Auth::user()?->hasRole('manager'))
                    ->action(function (PurchaseOrder $record) {
                        $record->approve(Auth::user()->name);
                        Notification::make()->title("PO {$record->po_number} diluluskan")->success()->send();
                    }),

                Action::make('markAsSent')
                    ->label('Tanda Dihantar ke Supplier')
                    ->icon('heroicon-o-truck')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrder::STATUS_APPROVED)
                    ->action(function (PurchaseOrder $record) {
                        $record->markAsSent();
                        Notification::make()->title("PO {$record->po_number} ditanda Sent")->success()->send();
                    }),

                Action::make('cancel')
                    ->label('Batal')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseOrder $record) => in_array($record->status, PurchaseOrder::CANCELLABLE_STATUSES, true))
                    ->action(function (PurchaseOrder $record) {
                        $record->cancel();
                        Notification::make()->title("PO {$record->po_number} dibatalkan")->danger()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
