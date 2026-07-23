<?php

namespace App\Filament\Resources\PhysicalGoldCategories\Pages;

use App\Filament\Resources\PhysicalGoldCategories\PhysicalGoldCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPhysicalGoldCategories extends ListRecords
{
    protected static string $resource = PhysicalGoldCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
