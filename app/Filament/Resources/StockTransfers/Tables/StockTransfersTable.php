<?php

namespace App\Filament\Resources\StockTransfers\Tables;

use App\Models\StockTransfer;
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

class StockTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transfer_number')->label('No. Transfer')->searchable()->sortable(),
                TextColumn::make('internal_code')->label('Kod Design')->searchable(),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25),
                TextColumn::make('from_store')->label('Dari')->badge()->color('gray'),
                TextColumn::make('to_store')->label('Ke')->badge()->color('primary'),
                TextColumn::make('qty')->label('Kuantiti')->numeric()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        StockTransfer::STATUS_REQUESTED => 'gray',
                        StockTransfer::STATUS_IN_TRANSIT => 'warning',
                        StockTransfer::STATUS_RECEIVED => 'success',
                        StockTransfer::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requested_by')->label('Diminta oleh'),
                TextColumn::make('requested_at')->label('Tarikh Minta')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    StockTransfer::STATUS_REQUESTED => 'Requested',
                    StockTransfer::STATUS_IN_TRANSIT => 'In Transit',
                    StockTransfer::STATUS_RECEIVED => 'Received',
                    StockTransfer::STATUS_CANCELLED => 'Cancelled',
                ]),
                SelectFilter::make('from_store')->label('Dari Cawangan')
                    ->options(fn () => StockTransfer::query()->distinct()->pluck('from_store', 'from_store')),
                SelectFilter::make('to_store')->label('Ke Cawangan')
                    ->options(fn () => StockTransfer::query()->distinct()->pluck('to_store', 'to_store')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (StockTransfer $record) => $record->status === StockTransfer::STATUS_REQUESTED),

                Action::make('advance')
                    ->label(fn (StockTransfer $record) => $record->status === StockTransfer::STATUS_REQUESTED ? 'Tanda In Transit' : 'Tanda Diterima')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (StockTransfer $record) => in_array($record->status, [
                        StockTransfer::STATUS_REQUESTED, StockTransfer::STATUS_IN_TRANSIT,
                    ], true))
                    ->action(function (StockTransfer $record) {
                        $record->advance(Auth::user()->name);
                        Notification::make()->title("Transfer {$record->transfer_number} -> {$record->fresh()->status}")->success()->send();
                    }),

                Action::make('cancel')
                    ->label('Batal')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (StockTransfer $record) => $record->status !== StockTransfer::STATUS_RECEIVED
                        && $record->status !== StockTransfer::STATUS_CANCELLED)
                    ->action(function (StockTransfer $record) {
                        $record->cancel();
                        Notification::make()->title("Transfer {$record->transfer_number} dibatalkan")->danger()->send();
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
