<?php

namespace App\Filament\Resources\PhysicalGoldPurities\Pages;

use App\Filament\Resources\PhysicalGoldPurities\PhysicalGoldPurityResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPhysicalGoldPurity extends ViewRecord
{
    protected static string $resource = PhysicalGoldPurityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
