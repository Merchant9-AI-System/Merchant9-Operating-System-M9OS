<?php

namespace App\Filament\Resources\BranchDemandRequests\Tables;

use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BranchDemandRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'lines as critical_lines_count' => fn (Builder $q) => $q->where(
                    'fulfillment_status',
                    BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL,
                ),
            ]))
            ->columns([
                IconColumn::make('critical_lines_count')->label('Kritikal')
                    ->boolean()
                    ->trueIcon(Heroicon::ExclamationTriangle)
                    ->falseIcon(null)
                    ->trueColor('danger')
                    ->getStateUsing(fn (BranchDemandRequest $record) => $record->critical_lines_count > 0),
                TextColumn::make('request_number')->label('No. Permintaan')->searchable()->sortable(),
                TextColumn::make('store_code')->label('Cawangan')
                    ->formatStateUsing(fn (?string $state) => trim((string) $state))
                    ->badge()->sortable(),
                TextColumn::make('lines_count')->label('Bil. Item')->counts('lines'),
                TextColumn::make('submitted_by_label')->label('Dihantar oleh'),
                TextColumn::make('submitted_at')->label('Tarikh Hantar')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('reviewedBy.name')->label('Disemak oleh')->placeholder('-')->toggleable(),
            ])
            ->filters([
                // Utk BO utamakan tengok stok kritikal dahulu (rujuk BranchDemandRequestLine::
                // FULFILLMENT_STOK_KRITIKAL - dicetuskan drpd toggle "Kritikal" staf cawangan).
                Filter::make('has_critical')
                    ->label('Ada Item Kritikal')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('lines', fn (Builder $q) => $q->where(
                        'fulfillment_status',
                        BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL,
                    ))),
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
                    // Header status TAK dipakai lagi (rujuk App\Models\BranchDemandRequest
                    // dokblok "satu permintaan setiap cawangan") - kewujudan line PENDING SAHAJA
                    // yg tentukan boleh batal atau tak.
                    ->visible(fn (BranchDemandRequest $record) => $record->lines->isNotEmpty()
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
