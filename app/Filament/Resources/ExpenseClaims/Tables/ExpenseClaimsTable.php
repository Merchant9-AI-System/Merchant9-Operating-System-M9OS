<?php

namespace App\Filament\Resources\ExpenseClaims\Tables;

use App\Models\ExpenseClaim;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ExpenseClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('claim_number')->label('No. Claim')->searchable()->sortable(),
                TextColumn::make('claimant_name')->label('Claimant')->searchable()->sortable(),
                TextColumn::make('createdBy.name')->label('Dicipta oleh')->placeholder('-')->toggleable(),
                TextColumn::make('claim_month')->label('Bulan')->date('F Y')->sortable(),
                TextColumn::make('total_amount')->label('Jumlah')
                    ->money('MYR')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        ExpenseClaim::STATUS_SUBMITTED => 'warning',
                        ExpenseClaim::STATUS_APPROVED => 'success',
                        ExpenseClaim::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')->label('Tarikh Hantar')->dateTime('d/m/Y H:i')->sortable()->placeholder('-'),
                TextColumn::make('approvedBy.name')->label('Diluluskan/Ditolak oleh')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(
                    [ExpenseClaim::STATUS_SUBMITTED, ExpenseClaim::STATUS_APPROVED, ExpenseClaim::STATUS_REJECTED],
                    [ExpenseClaim::STATUS_SUBMITTED, ExpenseClaim::STATUS_APPROVED, ExpenseClaim::STATUS_REJECTED],
                )),
                SelectFilter::make('claim_month')
                    ->label('Bulan')
                    ->options(fn () => ExpenseClaim::query()->distinct()->orderByDesc('claim_month')
                        ->pluck('claim_month')->mapWithKeys(fn ($v) => [$v->format('Y-m-d') => $v->format('F Y')])),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Lulus')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ExpenseClaim $record) => $record->status === ExpenseClaim::STATUS_SUBMITTED
                        && (bool) Auth::user()?->can('approve', $record))
                    ->action(function (ExpenseClaim $record) {
                        $record->approve(Auth::user());
                        Notification::make()->title("Claim {$record->claim_number} diluluskan")->success()->send();
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')
                            ->label('Sebab Ditolak')
                            ->required()
                            ->rows(3),
                    ])
                    ->visible(fn (ExpenseClaim $record) => $record->status === ExpenseClaim::STATUS_SUBMITTED
                        && (bool) Auth::user()?->can('reject', $record))
                    ->action(function (array $data, ExpenseClaim $record) {
                        $record->reject(Auth::user(), $data['reason']);
                        Notification::make()->title("Claim {$record->claim_number} ditolak")->danger()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
