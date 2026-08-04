<?php

namespace App\Filament\Resources\BranchDemandRequests\Schemas;

use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use Filament\Infolists\Components\ImageEntry;
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
                                    ->formatStateUsing(fn (string $state) => match ($state) {
                                        BranchDemandRequest::STATUS_SUBMITTED => 'Menunggu Semakan',
                                        BranchDemandRequest::STATUS_REVIEWED => 'Disemak',
                                        BranchDemandRequest::STATUS_PROCESSING => 'Diproses',
                                        BranchDemandRequest::STATUS_COMPLETED => 'Selesai',
                                        BranchDemandRequest::STATUS_CANCELLED => 'Dibatalkan',
                                        default => $state,
                                    })
                                    ->color(fn (string $state) => match ($state) {
                                        BranchDemandRequest::STATUS_SUBMITTED => 'warning',
                                        BranchDemandRequest::STATUS_REVIEWED => 'success',
                                        BranchDemandRequest::STATUS_PROCESSING => 'info',
                                        BranchDemandRequest::STATUS_COMPLETED => 'success',
                                        BranchDemandRequest::STATUS_CANCELLED => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('submitted_by_label')->label('Dihantar oleh'),
                                TextEntry::make('submitted_at')->label('Tarikh Hantar')->dateTime('d/m/Y H:i'),
                                TextEntry::make('reviewedBy.name')->label('Disemak oleh')->placeholder('-'),
                                TextEntry::make('reviewed_at')->label('Tarikh Semak')->dateTime('d/m/Y H:i')->placeholder('-'),
                                TextEntry::make('notes')->label('Nota')->placeholder('-')->columnSpanFull(),
                            ]),
                    ])->columnSpanFull(),

                // TODO: Biarkan comment
                // Section::make('Item Diminta')
                //     ->schema([
                //         RepeatableEntry::make('lines')
                //             ->hiddenLabel()
                //             ->schema([
                //                 Grid::make(10)
                //                     ->schema([
                //                         ImageEntry::make('image_url')->label('Gambar Rujukan')
                //                             ->visible(fn (BranchDemandRequestLine $record) => filled($record->image_url))
                //                             ->square()
                //                             ->imageSize(60),
                //                         TextEntry::make('internal_code')->label('Kod Design')
                //                             ->placeholder(fn (BranchDemandRequestLine $record) => $record->source_type !== BranchDemandRequestLine::SOURCE_CATALOG ? 'HQ sahkan kod' : '-'),
                //                         TextEntry::make('source_type')->label('Sumber')->badge()
                //                             ->formatStateUsing(fn (?string $state) => match ($state) {
                //                                 BranchDemandRequestLine::SOURCE_WEB => 'Laman Web',
                //                                 BranchDemandRequestLine::SOURCE_UPLOAD => 'Gambar Sendiri',
                //                                 default => 'Katalog',
                //                             })
                //                             ->color(fn (?string $state) => match ($state) {
                //                                 BranchDemandRequestLine::SOURCE_WEB => 'warning',
                //                                 BranchDemandRequestLine::SOURCE_UPLOAD => 'info',
                //                                 default => 'gray',
                //                             }),
                //                         TextEntry::make('item_desc')->label('Keterangan')->placeholder('-'),
                //                         TextEntry::make('size')->label('Saiz')->placeholder('-'),
                //                         TextEntry::make('weight')->label('Berat')->placeholder('-')
                //                             ->formatStateUsing(fn (?string $state) => filled($state) ? "{$state}g" : null),
                //                         TextEntry::make('qty_requested')->label('Diminta')->numeric(),
                //                         TextEntry::make('qty_approved')->label('Diluluskan')->numeric()->placeholder('-'),
                //                         TextEntry::make('line_status')->label('Status')->badge()
                //                             ->color(fn (string $state) => match ($state) {
                //                                 BranchDemandRequestLine::STATUS_APPROVED => 'success',
                //                                 BranchDemandRequestLine::STATUS_REJECTED => 'danger',
                //                                 default => 'gray',
                //                             }),
                //                         TextEntry::make('fulfillment_status')->label('Progress')->badge()
                //                             ->formatStateUsing(fn (?string $state) => BranchDemandRequestLine::FULFILLMENT_LABELS[$state] ?? $state)
                //                             ->color(fn (?string $state) => BranchDemandRequestLine::FULFILLMENT_COLORS[$state] ?? 'gray'),
                //                     ]),
                //             ]),
                //     ]),
            ]);
    }
}
