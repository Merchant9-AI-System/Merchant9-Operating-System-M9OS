<?php

namespace App\Filament\Resources\InventoryPieces\Pages;

use App\Filament\Resources\InventoryPieces\InventoryPieceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInventoryPieces extends ListRecords
{
    protected static string $resource = InventoryPieceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }
}
