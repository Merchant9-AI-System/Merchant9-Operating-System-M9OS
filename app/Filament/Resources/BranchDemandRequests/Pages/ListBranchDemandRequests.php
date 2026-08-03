<?php

namespace App\Filament\Resources\BranchDemandRequests\Pages;

use App\Filament\Resources\BranchDemandRequests\BranchDemandRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListBranchDemandRequests extends ListRecords
{
    protected static string $resource = BranchDemandRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Buat Permintaan Baharu'),
            // ->visible(fn () => filled(Auth::user()?->store_code)),
        ];
    }
}
