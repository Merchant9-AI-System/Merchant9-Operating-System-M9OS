<?php

namespace App\Filament\Resources\BudgetPeriods\Pages;

use App\Filament\Resources\BudgetPeriods\BudgetPeriodResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBudgetPeriod extends EditRecord
{
    protected static string $resource = BudgetPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
