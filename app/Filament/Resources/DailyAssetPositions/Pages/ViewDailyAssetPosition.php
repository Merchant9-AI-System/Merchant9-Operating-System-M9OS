<?php

namespace App\Filament\Resources\DailyAssetPositions\Pages;

use App\Filament\Resources\DailyAssetPositions\DailyAssetPositionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDailyAssetPosition extends ViewRecord
{
    protected static string $resource = DailyAssetPositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
