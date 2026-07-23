<?php

namespace App\Filament\Resources\PhysicalGoldPurities\Pages;

use App\Filament\Resources\PhysicalGoldPurities\PhysicalGoldPurityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPhysicalGoldPurities extends ListRecords
{
    protected static string $resource = PhysicalGoldPurityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
