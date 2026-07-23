<?php

namespace App\Filament\Resources\PhysicalGoldReports\Pages;

use App\Filament\Resources\PhysicalGoldReports\PhysicalGoldReportResource;
use App\Models\PhysicalGoldReport;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPhysicalGoldReport extends ViewRecord
{
    protected static string $resource = PhysicalGoldReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (PhysicalGoldReport $record) => $record->status === PhysicalGoldReport::STATUS_DRAFT),
        ];
    }
}
