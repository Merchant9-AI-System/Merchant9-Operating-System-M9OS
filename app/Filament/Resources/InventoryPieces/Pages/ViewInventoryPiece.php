<?php

namespace App\Filament\Resources\InventoryPieces\Pages;

use App\Filament\Resources\InventoryPieces\InventoryPieceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryPiece extends ViewRecord
{
    protected static string $resource = InventoryPieceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
