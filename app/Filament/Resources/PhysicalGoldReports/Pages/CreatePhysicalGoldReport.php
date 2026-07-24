<?php

namespace App\Filament\Resources\PhysicalGoldReports\Pages;

use App\Filament\Resources\PhysicalGoldReports\PhysicalGoldReportResource;
use App\Support\PhysicalGoldReportLineMapper;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CreatePhysicalGoldReport extends CreateRecord
{
    protected static string $resource = PhysicalGoldReportResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * Seksyen borang (used_gold_hq_lines, stock_branch_lines, dll.) bukan lajur
     * PhysicalGoldReport sebenar - override sepenuhnya (bukan mutateFormDataBeforeCreate)
     * supaya kunci maya tu tak terhantar terus ke create() model.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['prepared_by_id'] = Auth::id();
        $data['prepared_by'] = Auth::user()?->name;

        $report = static::getModel()::create(Arr::except($data, PhysicalGoldReportLineMapper::virtualKeys()));

        PhysicalGoldReportLineMapper::syncLinesFromFormState($report, $data);

        return $report;
    }
}
