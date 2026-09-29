<?php

namespace App\Filament\Resources\InventoryStatuses\Pages;

use App\Filament\Resources\InventoryStatuses\InventoryStatusResource;
use App\Filament\Resources\InventoryStatuses\Widgets\InventoryStatusOverview;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;

class ListInventoryStatuses extends ListRecords
{
    // Tanpa ni, Page::getWidgetData() default pulang [] - header widget InventoryStatusOverview
    // (guna #[Reactive] props macam tableSearch/tableFilters) tak pernah terima nilai SEBENAR
    // drpd jadual ni, punca widget "stuck loading" (wire:init tak pernah selesai kerana
    // reactive prop tiada sumber untuk di-hydrate).
    use ExposesTableToWidgets;

    protected static string $resource = InventoryStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryStatusOverview::class,
        ];
    }
}
