<?php

namespace App\Filament\Resources\PhysicalGoldPurities\Pages;

use App\Filament\Resources\PhysicalGoldPurities\PhysicalGoldPurityResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPhysicalGoldPurity extends EditRecord
{
    protected static string $resource = PhysicalGoldPurityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
