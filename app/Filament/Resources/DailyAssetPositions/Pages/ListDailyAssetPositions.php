<?php

namespace App\Filament\Resources\DailyAssetPositions\Pages;

use App\Filament\Resources\DailyAssetPositions\DailyAssetPositionResource;
use App\Filament\Widgets\DailyAssetPositionListSummary;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyAssetPositions extends ListRecords
{
    protected static string $resource = DailyAssetPositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DailyAssetPositionListSummary::class,
        ];
    }
}
