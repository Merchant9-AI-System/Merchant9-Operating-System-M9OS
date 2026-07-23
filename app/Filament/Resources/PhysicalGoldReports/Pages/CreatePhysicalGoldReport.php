<?php

namespace App\Filament\Resources\PhysicalGoldReports\Pages;

use App\Filament\Resources\PhysicalGoldReports\PhysicalGoldReportResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePhysicalGoldReport extends CreateRecord
{
    protected static string $resource = PhysicalGoldReportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['prepared_by_id'] = Auth::id();
        $data['prepared_by'] = Auth::user()?->name;

        return $data;
    }
}
