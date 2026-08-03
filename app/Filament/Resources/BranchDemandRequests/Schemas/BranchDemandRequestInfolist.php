<?php

namespace App\Filament\Resources\BranchDemandRequests\Schemas;

use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BranchDemandRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maklumat Permintaan')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('request_number')->label('No. Permintaan'),
                                TextEntry::make('store_code')->label('Cawangan')
                                    ->formatStateUsing(fn (?string $state) => trim((string) $state)),
                                TextEntry::make('status')->label('Status')->badge()
                                    ->color(fn (string $state) => match ($state) {
                                        BranchDemandRequest::STATUS_SUBMITTED => 'warning',
                                        BranchDemandRequest::STATUS_REVIEWED => 'success',
                                        BranchDemandRequest::STATUS_CANCELLED => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('submitted_by_label')->label('Dihantar oleh'),
                                TextEntry::make('submitted_at')->label('Tarikh Hantar')->dateTime('d/m/Y H:i'),
                                TextEntry::make('reviewedBy.name')->label('Disemak oleh')->placeholder('-'),
                                TextEntry::make('reviewed_at')->label('Tarikh Semak')->dateTime('d/m/Y H:i')->placeholder('-'),
                                TextEntry::make('notes')->label('Nota')->placeholder('-')->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Item Diminta')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(5)
                                    ->schema([
                                        TextEntry::make('internal_code')->label('Kod Design'),
                                        TextEntry::make('item_desc')->label('Keterangan')->placeholder('-'),
                                        TextEntry::make('qty_requested')->label('Diminta')->numeric(),
                                        TextEntry::make('qty_approved')->label('Diluluskan')->numeric()->placeholder('-'),
                                        TextEntry::make('line_status')->label('Status')->badge()
                                            ->color(fn (string $state) => match ($state) {
                                                BranchDemandRequestLine::STATUS_APPROVED => 'success',
                                                BranchDemandRequestLine::STATUS_REJECTED => 'danger',
                                                default => 'gray',
                                            }),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
