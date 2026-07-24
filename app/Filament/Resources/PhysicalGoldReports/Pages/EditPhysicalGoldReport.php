<?php

namespace App\Filament\Resources\PhysicalGoldReports\Pages;

use App\Filament\Resources\PhysicalGoldReports\PhysicalGoldReportResource;
use App\Support\PhysicalGoldReportLineMapper;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditPhysicalGoldReport extends EditRecord
{
    protected static string $resource = PhysicalGoldReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /** Susun semula seksyen tetap (ketulenan/cawangan pra-isi) drpd baris sedia ada. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, PhysicalGoldReportLineMapper::formStateFromReport($this->record));
    }

    /** @see CreatePhysicalGoldReport::handleRecordCreation() - logik sync sama, rujuk situ. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::except($data, PhysicalGoldReportLineMapper::virtualKeys()));

        PhysicalGoldReportLineMapper::syncLinesFromFormState($record, $data);

        return $record;
    }
}
