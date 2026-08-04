<?php

namespace App\Filament\Resources\BranchDemandRequests\RelationManagers;

use App\Models\BranchDemandRequestLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paparan SEMUA item (lines) SATU permintaan - versi boleh susun/tapis drpd senarai statik di
 * infolist (rujuk BranchDemandRequestInfolist) - BO boleh susun ikut kritikal/tarikh/kategori/
 * sumber (laman web) terus drpd header lajur. TIADA create/edit/delete di sini sengaja - line
 * diurus SEPENUHNYA via borang cawangan (store()) & action Semak/Kemaskini Status di
 * ViewBranchDemandRequest, bukan CRUD ad-hoc.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Senarai Item';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item_desc')
            ->columns([
                ImageColumn::make('image_url')->label('Gambar')->square()->imageSize(50)->url(fn (?string $state): ?string => $state, true)->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('internal_code')->label('Kod Design')
                    ->placeholder(fn (BranchDemandRequestLine $record) => $record->source_type !== BranchDemandRequestLine::SOURCE_CATALOG ? 'HQ sahkan kod' : '-'),
                TextColumn::make('item_desc')->label('Keterangan')->searchable()->wrap()->placeholder('-'),
                TextColumn::make('category_name')->label('Kategori')
                    ->sortable()->searchable()->placeholder('-'),
                TextColumn::make('source_type')->label('Sumber')->badge()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        BranchDemandRequestLine::SOURCE_WEB => 'Laman Web',
                        BranchDemandRequestLine::SOURCE_UPLOAD => 'Gambar Sendiri',
                        default => 'Katalog',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        BranchDemandRequestLine::SOURCE_WEB => 'warning',
                        BranchDemandRequestLine::SOURCE_UPLOAD => 'info',
                        default => 'gray',
                    }),
                // Nama lajur "is_critical" SENGAJA berlainan drpd lajur "Progress" di bawah
                // (kedua2 baca fulfillment_status SAMA) - kekalkan nama unik supaya state
                // susun/toggle Livewire dua2 lajur x bertembung.
                TextColumn::make('is_critical')->label('Kritikal')
                    ->badge()
                    ->getStateUsing(fn (BranchDemandRequestLine $record) => $record->fulfillment_status)
                    ->formatStateUsing(fn (?string $state) => $state === BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL ? 'Kritikal' : '-')
                    ->color(fn (?string $state) => $state === BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL ? 'danger' : 'gray')
                    // Susun ikut "kritikal dahulu" (boolean tersirat), bukan abjad mentah
                    // fulfillment_status - rujuk BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL.
                    ->sortable(query: function (Builder $query, string $direction) {
                        $query->orderByRaw(
                            '(fulfillment_status = ?) '.($direction === 'desc' ? 'DESC' : 'ASC'),
                            [BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL]
                        );
                    }),
                TextColumn::make('qty_requested')->label('Diminta')->numeric(),
                TextColumn::make('qty_approved')->label('Diluluskan')->numeric()->placeholder('-'),
                TextColumn::make('line_status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        BranchDemandRequestLine::STATUS_APPROVED => 'success',
                        BranchDemandRequestLine::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('fulfillment_status')->label('Progress')->badge()
                    ->formatStateUsing(fn (?string $state) => BranchDemandRequestLine::FULFILLMENT_LABELS[$state] ?? $state)
                    ->color(fn (?string $state) => BranchDemandRequestLine::FULFILLMENT_COLORS[$state] ?? 'gray')
                    ->sortable(),
                TextColumn::make('created_at')->label('Tarikh')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Filter::make('has_critical')
                    ->label('Kritikal')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('fulfillment_status', BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL)),
                SelectFilter::make('source_type')
                    ->label('Sumber')
                    ->options([
                        BranchDemandRequestLine::SOURCE_CATALOG => 'Katalog',
                        BranchDemandRequestLine::SOURCE_WEB => 'Laman Web',
                        BranchDemandRequestLine::SOURCE_UPLOAD => 'Gambar Sendiri',
                    ]),
                SelectFilter::make('category_name')
                    ->label('Kategori')
                    ->options(fn () => BranchDemandRequestLine::query()
                        ->whereNotNull('category_name')
                        ->distinct()
                        ->orderBy('category_name')
                        ->pluck('category_name', 'category_name')),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
