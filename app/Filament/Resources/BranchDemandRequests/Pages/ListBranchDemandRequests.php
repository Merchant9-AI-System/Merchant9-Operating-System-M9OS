<?php

namespace App\Filament\Resources\BranchDemandRequests\Pages;

use App\Filament\Resources\BranchDemandRequests\BranchDemandRequestResource;
use App\Filament\Resources\BranchDemandRequests\Widgets\AllBranchDemandLinesTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListBranchDemandRequests extends ListRecords
{
    protected static string $resource = BranchDemandRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make()
            //     ->label('Buat Permintaan Baharu'),
            // ->visible(fn () => filled(Auth::user()?->store_code)),
        ];
    }

    // Jadual "Semua Item" (rujuk AllBranchDemandLinesTable) - footer widget muncul TERUS DI
    // BAWAH jadual permintaan utama di atas (bukan header, bukan tab berasingan).
    protected function getFooterWidgets(): array
    {
        return [
            AllBranchDemandLinesTable::class,
        ];
    }
}
