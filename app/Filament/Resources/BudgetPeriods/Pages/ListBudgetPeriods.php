<?php

namespace App\Filament\Resources\BudgetPeriods\Pages;

use App\Filament\Resources\BudgetPeriods\BudgetPeriodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBudgetPeriods extends ListRecords
{
    protected static string $resource = BudgetPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
