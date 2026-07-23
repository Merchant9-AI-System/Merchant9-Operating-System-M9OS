<?php

namespace App\Filament\Resources\PhysicalGoldReports\Pages;

use App\Filament\Resources\PhysicalGoldReports\PhysicalGoldReportResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPhysicalGoldReport extends EditRecord
{
    protected static string $resource = PhysicalGoldReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
