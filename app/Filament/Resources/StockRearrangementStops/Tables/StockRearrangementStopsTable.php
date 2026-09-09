<?php

namespace App\Filament\Resources\StockRearrangementStops\Tables;

use App\Models\StockRearrangementStop;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class StockRearrangementStopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('internal_code')->label('Kod Design')->searchable()->sortable(),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25),
                TextColumn::make('reason')->label('Sebab')->wrap()->limit(80)
                    ->extraHeaderAttributes(['style' => 'min-width: 20rem'])
                    ->extraCellAttributes(['style' => 'min-width: 20rem']),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        StockRearrangementStop::STATUS_PENDING => 'warning',
                        StockRearrangementStop::STATUS_APPROVED => 'success',
                        StockRearrangementStop::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requested_by')->label('Diminta oleh'),
                TextColumn::make('requested_at')->label('Tarikh Diminta')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('reviewed_by')->label('Disemak oleh')->placeholder('-'),
                TextColumn::make('reviewed_at')->label('Tarikh Disemak')->dateTime('d/m/Y H:i')->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    StockRearrangementStop::STATUS_PENDING => 'Pending',
                    StockRearrangementStop::STATUS_APPROVED => 'Approved',
                    StockRearrangementStop::STATUS_REJECTED => 'Rejected',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Lulus')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (StockRearrangementStop $record) => $record->status === StockRearrangementStop::STATUS_PENDING
                        && (bool) Auth::user()?->can('approve', $record))
                    ->action(function (StockRearrangementStop $record) {
                        $record->approve(Auth::user()->name);
                        Notification::make()->title("Stop {$record->internal_code} diluluskan")->success()->send();
                    }),

                Action::make('reject')
                    ->label('Tolak')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (StockRearrangementStop $record) => in_array($record->status, [
                        StockRearrangementStop::STATUS_PENDING, StockRearrangementStop::STATUS_APPROVED,
                    ], true) && (bool) Auth::user()?->can('reject', $record))
                    ->action(function (StockRearrangementStop $record) {
                        $record->reject(Auth::user()->name);
                        Notification::make()->title("Stop {$record->internal_code} ditolak - design akan muncul semula dlm cadangan Rearrange")->danger()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
