<?php

namespace App\Filament\Resources\BranchMonthlyTargets\Pages;

use App\Filament\Resources\BranchMonthlyTargets\BranchMonthlyTargetResource;
use App\Models\BranchMonthlyTarget;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditBranchMonthlyTarget extends EditRecord
{
    protected static string $resource = BranchMonthlyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * `month` ialah tarikh (Carbon, zon aplikasi). attributesToArray() menyerikannya ke UTC (2026-11-01 MYT menjadi
     * 2026-10-31) lalu pilihan bulan "tak sah" - maka tetapkan terus drpd rekod.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['month'] = $this->record->month->toDateString();

        return $data;
    }

    /** Kunci (cawangan, bulan) tak berubah; nilai & catatan ditulis melalui record() utk direkod. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var BranchMonthlyTarget $record */
        return BranchMonthlyTarget::record(
            $record->store_code,
            $record->month,
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
