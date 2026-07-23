<?php

namespace App\Filament\Resources\PhysicalGoldCategories\Pages;

use App\Filament\Resources\PhysicalGoldCategories\PhysicalGoldCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPhysicalGoldCategory extends EditRecord
{
    protected static string $resource = PhysicalGoldCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
