<?php

namespace App\Filament\Resources\BranchDemandRequests\Pages;

use App\Filament\Resources\BranchDemandRequests\BranchDemandRequestResource;
use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;

class ViewBranchDemandRequest extends ViewRecord
{
    protected static string $resource = BranchDemandRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Landasan progress BO SENDIRI (Listed -> Dah Order -> ... -> Dah Delivery, dll) -
            // rujuk BranchDemandRequestLine::FULFILLMENT_* & dokblok medan fulfillment_status.
            // Sesetengah permintaan makan 2-3 hari bekerja utk selesai - action ni benarkan BO
            // kemaskini progress tiap line dari semasa ke semasa, staf cawangan nampak progress
            // tsb di Create.vue ("Item Sedia Ada"). Line kini AUTO-APPROVE semasa dicipta (tiada
            // lagi langkah semakan berasingan), jadi TIADA tapis line_status di sini.
            Action::make('updateFulfillment')
                ->label('Kemaskini Status')
                ->icon('heroicon-o-truck')
                ->color('info')
                ->visible(fn (BranchDemandRequest $record) => (bool) Auth::user()?->can('Update:BranchDemandRequest')
                    && $record->lines->isNotEmpty())
                ->schema(fn (BranchDemandRequest $record) => $record->lines->map(fn ($line) => Section::make(self::lineTitle($line))
                    ->description("Diminta: {$line->qty_requested} unit")
                    ->schema([
                        Select::make("fulfillment_{$line->id}")
                            ->label('Status Progress')
                            ->options(BranchDemandRequestLine::FULFILLMENT_LABELS)
                            ->default($line->fulfillment_status)
                            ->required(),
                    ]))->all())
                ->action(function (array $data, BranchDemandRequest $record) {
                    foreach ($record->lines as $line) {
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
                ->modalDescription('Semua item dlm permintaan ni akan ditanda "Dah Delivery".')
                ->visible(fn (BranchDemandRequest $record) => (bool) Auth::user()?->can('Update:BranchDemandRequest')
                    && $record->lines->isNotEmpty())
                ->action(function (BranchDemandRequest $record) {
                    $record->lines->each(fn ($line) => $line->update([
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
