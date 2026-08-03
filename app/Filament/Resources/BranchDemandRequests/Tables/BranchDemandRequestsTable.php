<?php

namespace App\Filament\Resources\BranchDemandRequests\Tables;

use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BranchDemandRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')->label('No. Permintaan')->searchable()->sortable(),
                TextColumn::make('store_code')->label('Cawangan')
                    ->formatStateUsing(fn (?string $state) => trim((string) $state))
                    ->badge()->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        BranchDemandRequest::STATUS_SUBMITTED => 'warning',
                        BranchDemandRequest::STATUS_REVIEWED => 'success',
                        BranchDemandRequest::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('lines_count')->label('Bil. Item')->counts('lines'),
                TextColumn::make('submitted_by_label')->label('Dihantar oleh'),
                TextColumn::make('submitted_at')->label('Tarikh Hantar')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('reviewedBy.name')->label('Disemak oleh')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    BranchDemandRequest::STATUS_SUBMITTED => 'Submitted',
                    BranchDemandRequest::STATUS_REVIEWED => 'Reviewed',
                    BranchDemandRequest::STATUS_CANCELLED => 'Cancelled',
                ]),
                SelectFilter::make('store_code')
                    ->label('Cawangan')
                    ->options(fn () => BranchDemandRequest::query()->distinct()->pluck('store_code', 'store_code')
                        ->mapWithKeys(fn ($v, $k) => [$k => trim((string) $k)])),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('cancel')
                    ->label('Batal')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BranchDemandRequest $record) => $record->status === BranchDemandRequest::STATUS_SUBMITTED
                        && $record->lines->every(fn ($l) => $l->line_status === BranchDemandRequestLine::STATUS_PENDING))
                    ->action(function (BranchDemandRequest $record) {
                        $record->cancel();
                        Notification::make()->title("Permintaan {$record->request_number} dibatalkan")->success()->send();
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
