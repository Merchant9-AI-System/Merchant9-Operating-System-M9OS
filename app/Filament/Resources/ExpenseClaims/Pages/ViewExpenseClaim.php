<?php

namespace App\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Models\ExpenseClaim;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewExpenseClaim extends ViewRecord
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Lulus')
                ->icon('heroicon-o-check-circle')
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
                ->icon('heroicon-o-x-circle')
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
        ];
    }
}
