<?php

namespace App\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListExpenseClaims extends ListRecords
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Pautan pintasan ke borang Inertia staf (rujuk ExpenseClaimController::index) - claim
            // baharu HANYA dicipta di sana, bukan Filament (TIADA CreateRecord page utk resource ni).
            Action::make('createClaim')
                ->label('Cipta Klaim')
                ->url(fn (): string => route('expense-claims.index'))
                ->openUrlInNewTab(),
        ];
    }
}
