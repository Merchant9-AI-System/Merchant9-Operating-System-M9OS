<?php

namespace App\Filament\Resources\DailyAssetPositions\Pages;

use App\Filament\Resources\DailyAssetPositions\DailyAssetPositionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDailyAssetPosition extends EditRecord
{
    protected static string $resource = DailyAssetPositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::user()?->name;

        return $data;
    }
}
