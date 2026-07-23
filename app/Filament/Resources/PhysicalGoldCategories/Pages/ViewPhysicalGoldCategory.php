<?php

namespace App\Filament\Resources\PhysicalGoldCategories\Pages;

use App\Filament\Resources\PhysicalGoldCategories\PhysicalGoldCategoryResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPhysicalGoldCategory extends ViewRecord
{
    protected static string $resource = PhysicalGoldCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
