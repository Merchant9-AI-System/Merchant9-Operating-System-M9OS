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
                // Header status TAK dipakai lagi (rujuk App\Models\BranchDemandRequest dokblok
                // "satu permintaan setiap cawangan") - guna terus kewujudan line PENDING sbg
                // penentu, bukan status header (yg kekal x berubah sekarang permintaan kekal
                // SATU rekod selama-lamanya, tak reset "Submitted" tiap kali line baharu masuk).
                ->visible(fn (BranchDemandRequest $record) => $record->lines->contains(fn ($l) => $l->line_status === BranchDemandRequestLine::STATUS_PENDING)
                    && (bool) Auth::user()?->can('Update:BranchDemandRequest'))
                ->schema(function (BranchDemandRequest $record) {
                    $pending = $record->lines->where('line_status', BranchDemandRequestLine::STATUS_PENDING);

                    return $pending->map(function ($line) {
                        $description = "Diminta: {$line->qty_requested} unit";
                        if ($line->source_type !== BranchDemandRequestLine::SOURCE_CATALOG) {
                            $description .= ' - HQ sahkan kod design sebenar';
                        }

                        return Section::make(self::lineTitle($line))
                            ->description($description)
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
                            ]);
                    })->all();
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

                    if ($record->submittedBy) {
                        Notification::make()
                            ->title("Permintaan {$record->request_number} telah disemak")
                            ->body('Item baharu dlm permintaan ni dah ada keputusan drpd HQ.')
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

            // Landasan progress BO SENDIRI (Listed -> Dah Order -> ... -> Dah Delivery, dll) -
            // BERASINGAN drpd keputusan Lulus/Tolak di atas (rujuk BranchDemandRequestLine::
            // FULFILLMENT_* & dokblok medan fulfillment_status). Sesetengah permintaan makan
            // 2-3 hari bekerja utk selesai - action ni benarkan BO kemaskini progress tiap line
            // yg DAH LULUS dari semasa ke semasa, staf cawangan nampak progress tsb di
            // RequestList.vue.
            Action::make('updateFulfillment')
                ->label('Kemaskini Status')
                ->icon('heroicon-o-truck')
                ->color('info')
                ->visible(fn (BranchDemandRequest $record) => (bool) Auth::user()?->can('Update:BranchDemandRequest')
                    && $record->lines->where('line_status', BranchDemandRequestLine::STATUS_APPROVED)->isNotEmpty())
                ->schema(function (BranchDemandRequest $record) {
                    $approved = $record->lines->where('line_status', BranchDemandRequestLine::STATUS_APPROVED);

                    return $approved->map(fn ($line) => Section::make(self::lineTitle($line))
                        ->description("Diluluskan: {$line->qty_approved} unit")
                        ->schema([
                            Select::make("fulfillment_{$line->id}")
                                ->label('Status Progress')
                                ->options(BranchDemandRequestLine::FULFILLMENT_LABELS)
                                ->default($line->fulfillment_status)
                                ->required(),
                        ]))->all();
                })
                ->action(function (array $data, BranchDemandRequest $record) {
                    $approved = $record->lines->where('line_status', BranchDemandRequestLine::STATUS_APPROVED);

                    foreach ($approved as $line) {
                        $newStatus = $data["fulfillment_{$line->id}"] ?? null;

                        if (filled($newStatus)) {
                            $line->update(['fulfillment_status' => $newStatus]);
                        }
                    }

                    Notification::make()->title('Status progress dikemaskini')->success()->send();
                }),

            Action::make('markAllDelivered')
                ->label('Tandakan Semua Selesai')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Semua item yg DAH LULUS dlm permintaan ni akan ditanda "Dah Delivery".')
                ->visible(fn (BranchDemandRequest $record) => (bool) Auth::user()?->can('Update:BranchDemandRequest')
                    && $record->lines->where('line_status', BranchDemandRequestLine::STATUS_APPROVED)->isNotEmpty())
                ->action(function (BranchDemandRequest $record) {
                    $record->lines->where('line_status', BranchDemandRequestLine::STATUS_APPROVED)
                        ->each(fn ($line) => $line->update([
                            'fulfillment_status' => BranchDemandRequestLine::FULFILLMENT_DAH_DELIVERY,
                        ]));

                    Notification::make()->title('Semua item ditanda Dah Delivery')->success()->send();
                }),
        ];
    }

    /** Tajuk Section utk satu line - kod design SEBENAR (catalog) atau label sumber (web/upload). */
    private static function lineTitle(BranchDemandRequestLine $line): string
    {
        return match ($line->source_type) {
            BranchDemandRequestLine::SOURCE_WEB => "[Laman Web] {$line->item_desc}",
            BranchDemandRequestLine::SOURCE_UPLOAD => "[Gambar Sendiri] {$line->item_desc}",
            default => "{$line->internal_code} - {$line->item_desc}",
        };
    }
}
