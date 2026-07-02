<?php

namespace App\Filament\Resources\BudgetPeriods\Pages;

use App\Filament\Resources\BudgetPeriods\BudgetPeriodResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBudgetPeriod extends ViewRecord
{
    protected static string $resource = BudgetPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
