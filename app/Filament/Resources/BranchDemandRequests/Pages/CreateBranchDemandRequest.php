<?php

namespace App\Filament\Resources\BranchDemandRequests\Pages;

use App\Filament\Resources\BranchDemandRequests\BranchDemandRequestResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateBranchDemandRequest extends CreateRecord
{
    protected static string $resource = BranchDemandRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $storeCode = Auth::user()?->store_code;
        abort_if(blank($storeCode), 422, 'Akaun anda tiada cawangan ditetapkan - hubungi HQ utk tetapkan cawangan sebelum boleh buat permintaan.');

        $data['store_code'] = $storeCode;
        $data['submitted_by_id'] = Auth::id();

        return static::getModel()::create($data);
    }

    /** Auto-route notifikasi ke semua hq_reviewer - staf cawangan TIDAK perlu pilih penerima secara manual (matlamat "bawah 3 minit"). */
    protected function afterCreate(): void
    {
        $this->record->notifyReviewers();
    }
}
