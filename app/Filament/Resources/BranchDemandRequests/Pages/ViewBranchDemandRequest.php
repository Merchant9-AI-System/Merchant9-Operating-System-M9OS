<?php

namespace App\Filament\Resources\BranchDemandRequests\Pages;

use App\Filament\Resources\BranchDemandRequests\BranchDemandRequestResource;
use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

class ViewBranchDemandRequest extends ViewRecord
{
    protected static string $resource = BranchDemandRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')
                ->label('Semak Permintaan')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('success')
                ->visible(fn (BranchDemandRequest $record) => $record->status === BranchDemandRequest::STATUS_SUBMITTED
                    && (bool) Auth::user()?->can('Update:BranchDemandRequest'))
                ->schema(function (BranchDemandRequest $record) {
                    $pending = $record->lines->where('line_status', BranchDemandRequestLine::STATUS_PENDING);

                    return $pending->map(fn ($line) => Section::make("{$line->internal_code} - {$line->item_desc}")
                        ->description("Diminta: {$line->qty_requested} unit")
                        ->schema([
                            Grid::make(3)->schema([
                                Select::make("decision_{$line->id}")
                                    ->label('Keputusan')
                                    ->options([
                                        BranchDemandRequestLine::STATUS_APPROVED => 'Lulus',
                                        BranchDemandRequestLine::STATUS_REJECTED => 'Tolak',
                                    ])
                                    ->default(BranchDemandRequestLine::STATUS_APPROVED)
                                    ->live()
                                    ->required(),
                                TextInput::make("qty_{$line->id}")
                                    ->label('Kuantiti Diluluskan')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue($line->qty_requested)
                                    ->default($line->qty_requested)
                                    ->visible(fn (Get $get) => $get("decision_{$line->id}") === BranchDemandRequestLine::STATUS_APPROVED)
                                    ->required(fn (Get $get) => $get("decision_{$line->id}") === BranchDemandRequestLine::STATUS_APPROVED),
                                TextInput::make("notes_{$line->id}")
                                    ->label('Nota (pilihan)'),
                            ]),
                        ]))->all();
                })
                ->action(function (array $data, BranchDemandRequest $record) {
                    $pending = $record->lines->where('line_status', BranchDemandRequestLine::STATUS_PENDING);

                    foreach ($pending as $line) {
                        $decision = $data["decision_{$line->id}"] ?? null;
                        if (blank($decision)) {
                            continue;
                        }

                        $line->review(
                            $decision,
                            $decision === BranchDemandRequestLine::STATUS_APPROVED ? (int) ($data["qty_{$line->id}"] ?? 0) : null,
                            $data["notes_{$line->id}"] ?? null,
                        );
                    }

                    $record->recalculateReviewStatus(Auth::user());

                    if ($record->fresh()->status === BranchDemandRequest::STATUS_REVIEWED && $record->submittedBy) {
                        Notification::make()
                            ->title("Permintaan {$record->request_number} telah disemak")
                            ->body('Semua item dlm permintaan ni dah ada keputusan drpd HQ.')
                            ->success()
                            ->actions([
                                Action::make('gotoPage')->label('Lihat')
                                    ->url(route('filament.admin.resources.branch-demand-requests.view', ['record' => $record->getKey()]))
                                    ->button(),
                            ])
                            ->sendToDatabase([$record->submittedBy]);
                    }

                    Notification::make()->title('Keputusan semakan disimpan')->success()->send();
                }),
        ];
    }
}
