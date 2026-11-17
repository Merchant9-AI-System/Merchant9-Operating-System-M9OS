<?php

namespace App\Filament\Resources\BranchMonthlyTargets\Pages;

use App\Filament\Resources\BranchMonthlyTargets\BranchMonthlyTargetResource;
use App\Models\BranchMonthlyTarget;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CreateBranchMonthlyTarget extends CreateRecord
{
    protected static string $resource = BranchMonthlyTargetResource::class;

    /** Semua penulisan melalui record() supaya semakan sentiasa direkod (dan sumber = HQ). */
    protected function handleRecordCreation(array $data): Model
    {
        return BranchMonthlyTarget::record(
            $data['store_code'],
            Carbon::parse($data['month']),
            (float) $data['target_rm'],
            (float) $data['target_grams'],
            $data['remark'] ?? null,
            Auth::user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
