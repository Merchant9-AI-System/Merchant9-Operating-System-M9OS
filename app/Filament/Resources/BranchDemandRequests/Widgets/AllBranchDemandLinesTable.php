<?php

namespace App\Filament\Resources\BranchDemandRequests\Widgets;

use App\Models\BranchDemandRequestLine;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Jadual SEMUA item (lines) MERENTASI semua permintaan - diletak SEBAGAI FOOTER WIDGET di
 * ListBranchDemandRequests (rujuk getFooterWidgets()), muncul TERUS DI BAWAH jadual permintaan
 * utama pd page List. BERBEZA drpd LinesRelationManager (RelationManagers/LinesRelationManager)
 * yg skop kpd SATU permintaan sahaja di page View - widget ni utk BO imbas/susun/tapis SEMUA
 * item drpd SEMUA permintaan sekali gus (rujuk No. Permintaan & Cawangan drpd relation "request"
 * utk kenal pasti asal setiap baris).
 */
class AllBranchDemandLinesTable extends TableWidget
{
    protected static ?string $heading = 'Senarai Semua Item';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->scopedQuery())
            ->columns([
                ImageColumn::make('image_url')->label('Gambar')->square()->imageSize(50)->url(fn(?string $state): ?string => $state, true)->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('request.request_number')->label('No. Permintaan')
                    ->searchable()->sortable()
                    ->url(fn(BranchDemandRequestLine $record) => $record->branch_demand_request_id
                        ? route('filament.admin.resources.branch-demand-requests.view', ['record' => $record->branch_demand_request_id])
                        : null),
                TextColumn::make('request.store_code')->label('Cawangan')
                    ->formatStateUsing(fn(?string $state) => trim((string) $state))
                    ->badge()->sortable(),
                TextColumn::make('internal_code')->label('Kod Design')
                    ->placeholder(fn(BranchDemandRequestLine $record) => $record->source_type !== BranchDemandRequestLine::SOURCE_CATALOG ? 'HQ sahkan kod' : '-'),
                TextColumn::make('item_desc')->label('Keterangan')->searchable()->wrap()->limit(40)->placeholder('-'),
                TextColumn::make('category_name')->label('Kategori')
                    ->sortable()->searchable()->placeholder('-'),
                TextColumn::make('source_type')->label('Sumber')->badge()
                    ->sortable()
                    ->formatStateUsing(fn(?string $state) => match ($state) {
                        BranchDemandRequestLine::SOURCE_WEB => 'Laman Web',
                        BranchDemandRequestLine::SOURCE_UPLOAD => 'Gambar Sendiri',
                        default => 'Katalog',
                    })
                    ->color(fn(?string $state) => match ($state) {
                        BranchDemandRequestLine::SOURCE_WEB => 'warning',
                        BranchDemandRequestLine::SOURCE_UPLOAD => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('is_critical')->label('Kritikal')
                    ->boolean()
                    ->trueIcon(Heroicon::ExclamationTriangle)
                    ->falseIcon(null)
                    ->trueColor('danger')
                    ->getStateUsing(fn(BranchDemandRequestLine $record) => $record->fulfillment_status === BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL)
                    // Susun ikut "kritikal dahulu" (boolean tersirat), bukan abjad mentah
                    // fulfillment_status - rujuk BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL.
                    ->sortable(query: function (Builder $query, string $direction) {
                        $query->orderByRaw(
                            '(fulfillment_status = ?) ' . ($direction === 'desc' ? 'DESC' : 'ASC'),
                            [BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL]
                        );
                    }),
                TextColumn::make('qty_requested')->label('Diminta')->numeric(),
                TextColumn::make('line_status')->label('Status')->badge()
                    ->color(fn(string $state) => match ($state) {
                        BranchDemandRequestLine::STATUS_APPROVED => 'success',
                        BranchDemandRequestLine::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('fulfillment_status')->label('Progress')->badge()
                    ->formatStateUsing(fn(?string $state) => BranchDemandRequestLine::FULFILLMENT_LABELS[$state] ?? $state)
                    ->color(fn(?string $state) => BranchDemandRequestLine::FULFILLMENT_COLORS[$state] ?? 'gray')
                    ->sortable(),
                TextColumn::make('created_at')->label('Tarikh')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Filter::make('has_critical')
                    ->label('Kritikal')
                    ->toggle()
                    ->query(fn(Builder $query) => $query->where('fulfillment_status', BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL)),
                SelectFilter::make('source_type')
                    ->label('Sumber')
                    ->options([
                        BranchDemandRequestLine::SOURCE_CATALOG => 'Katalog',
                        BranchDemandRequestLine::SOURCE_WEB => 'Laman Web',
                        BranchDemandRequestLine::SOURCE_UPLOAD => 'Gambar Sendiri',
                    ]),
                SelectFilter::make('category_name')
                    ->label('Kategori')
                    ->options(fn() => BranchDemandRequestLine::query()
                        ->whereNotNull('category_name')
                        ->distinct()
                        ->orderBy('category_name')
                        ->pluck('category_name', 'category_name')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * SAMA skop dgn BranchDemandRequestResource::getEloquentQuery() - staf cawangan biasa cuma
     * nampak line kepunyaan cawangan SENDIRI, HQ/CEO/super_admin nampak semua (jadual ni
     * merentasi permintaan, jadi skop MESTI diguna semula di sini, bukan cuma pd table utama).
     */
    protected function scopedQuery(): Builder
    {
        $query = BranchDemandRequestLine::query()->with('request');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user && ! $user->isSuperAdmin() && ! $user->hasRole(['hq_reviewer', 'ceo'])) {
            $query->whereHas('request', fn(Builder $q) => $q->where('store_code', $user->store_code ?? '__none__'));
        }

        return $query;
    }
}
