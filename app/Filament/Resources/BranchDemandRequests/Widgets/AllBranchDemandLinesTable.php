<?php

namespace App\Filament\Resources\BranchDemandRequests\Widgets;

use App\Filament\Exports\BranchDemandRequestLineExporter;
use App\Models\BranchDemandRequestLine;
use App\Models\User;
use App\Support\BranchDemandLineSummary;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Ringkasan SEMUA permintaan MERENTASI semua cawangan, dikumpul SATU BARIS setiap kod design
 * (bukan satu baris setiap line macam asal) - diletak SEBAGAI FOOTER WIDGET di
 * ListBranchDemandRequests (rujuk getFooterWidgets()). Kumpulan gabung kuantiti diminta SEMUA
 * cawangan bagi design yg SAMA (lajur Cawangan papar badge setiap cawangan yg minta), & lajur
 * Progress ialah dropdown terus (SelectColumn, bukan modal/action berasingan) - pilih status baharu
 * terus kemaskini SEMUA line APPROVED bagi kod design tsb, merentasi SEMUA cawangan, dalam SATU
 * simpanan (rujuk updateStateUsing() bawah).
 *
 * Kumpulan key = internal_code (di-trim) bila ada, jatuh balik ke item_desc (di-trim) bila tiada -
 * line SOURCE_WEB/SOURCE_UPLOAD tiada internal_code boleh dipercayai (rujuk BranchDemandRequestLine
 * dokblok SOURCE_WEB/SOURCE_UPLOAD), jadi dikumpul ikut keterangan sbg fallback.
 *
 * BERBEZA drpd LinesRelationManager (RelationManagers/LinesRelationManager) yg skop kpd SATU
 * permintaan sahaja (baris mentah, tiada agregat) - widget ni utk BO nampak GABUNGAN merentasi
 * semua permintaan/cawangan sekali gus.
 *
 * Eksport ("Eksport (ikut Kategori)") - rujuk App\Filament\Exports\BranchDemandRequestLineExporter
 * & App\Support\BranchDemandLineSummary (pecahan cawangan/nickname DIKONGSI antara widget ni &
 * exporter, SATU sumber kebenaran).
 */
class AllBranchDemandLinesTable extends TableWidget
{
    protected static ?string $heading = 'Ringkasan Ikut Kod Design';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected BranchDemandLineSummary $summary;

    protected function getSummary(): BranchDemandLineSummary
    {
        return $this->summary ??= new BranchDemandLineSummary;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->scopedQuery())
            ->headerActions([
                ExportAction::make()
                    ->label('Eksport (ikut Kategori)')
                    ->exporter(BranchDemandRequestLineExporter::class),
            ])
            ->columns([
                ImageColumn::make('image_url')->label('Gambar')->square()->imageSize(20)->url(fn(?string $state): ?string => $state, true)->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('internal_code')->label('Kod Design / Nama Item')
                    // getStateUsing() (bukan formatStateUsing()) sengaja - TextColumn::
                    // toEmbeddedHtml() semak KEKOSONGAN state MENTAH (getState(), drpd
                    // internal_code sebenar) SEBELUM formatStateUsing() sempat dipanggil, jadi
                    // fallback ke item_desc x akan pernah terpapar kalau internal_code=null bila
                    // guna formatStateUsing() - kena override STATE itu sendiri, bukan format lepas.
                    ->getStateUsing(fn(BranchDemandRequestLine $record) => filled($record->internal_code) ? $record->internal_code : $record->item_desc)
                    ->description(function (BranchDemandRequestLine $record) {
                        $base = trim(filled($record->internal_code) ? (string) $record->item_desc : '');

                        $nickname = $this->getSummary()->nicknameFor($record);

                        return filled($nickname) ? trim("{$base} · \"{$nickname}\"", ' ·') : $base;
                    }, position: 'below')
                    ->searchable(['internal_code', 'item_desc'])
                    ->wrap(),
                TextColumn::make('category_name')
                    ->label('Kategori')
                    ->searchable()
                    ->badge()
                    ->color('info')
                    ->wrap(),
                TextColumn::make('branches')->label('Cawangan')
                    ->badge()
                    ->getStateUsing(fn(BranchDemandRequestLine $record) => $this->getSummary()->branchBadgesFor($record))
                    // Merah kalau CAWANGAN tsb (bukan design keseluruhan) ada line stok_kritikal
                    // - staf boleh tanda kritikal per-permintaan (rujuk BranchDemandEntryController::
                    // store()), jadi satu design boleh kritikal utk SATU cawangan tapi biasa utk yg lain.
                    ->color(fn(BranchDemandRequestLine $record, string $state) => $this->getSummary()->branchIsCritical($record, $state) ? 'danger' : 'gray'),
                TextColumn::make('qty_requested_total')->label('Diminta')->numeric()->sortable(),
                TextColumn::make('specifications')->label('Spesifikasi')
                    ->getStateUsing(fn(BranchDemandRequestLine $record) => filled($record->sizes) ? "Saiz: {$record->sizes}" : null)
                    ->description(fn(BranchDemandRequestLine $record) => filled($record->weights) ? "Berat: {$record->weights}g" : null)
                    ->placeholder('-'),
                SelectColumn::make('fulfillment_status')->label('Progress')
                    ->options(BranchDemandRequestLine::FULFILLMENT_LABELS)
                    ->selectablePlaceholder(true)
                    ->getStateUsing(fn(BranchDemandRequestLine $record) => $this->groupProgressFor($record))
                    ->disabled(fn(BranchDemandRequestLine $record) => ! Auth::user()?->can('Update:BranchDemandRequest'))
                    ->updateStateUsing(function (BranchDemandRequestLine $record, $state) {
                        foreach ($this->approvedLinesForGroup($record) as $line) {
                            $line->update(['fulfillment_status' => $state]);
                        }

                        return $state;
                    }),
                TextColumn::make('created_at')->label('Tarikh')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Filter::make('has_critical')
                    ->label('Kritikal')
                    ->toggle()
                    ->query(fn(Builder $query) => $query->havingRaw(
                        'SUM(CASE WHEN branch_demand_request_lines.fulfillment_status = ? THEN 1 ELSE 0 END) > 0',
                        [BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL]
                    )),
                SelectFilter::make('category_name')
                    ->label('Kategori')
                    ->options(fn() => BranchDemandRequestLine::query()
                        ->whereNotNull('category_name')
                        ->distinct()
                        ->orderBy('category_name')
                        ->pluck('category_name', 'category_name')),
            ])
            // MySQL only_full_group_by: `id` di sini cuma MIN() aggregate alias, bukan lajur
            // sebenar boleh disusun - tiebreaker automatik Filament (order by qualified id) akan
            // pecah SQL kalau x dimatikan (rujuk Table::hasDefaultKeySort()).
            ->defaultKeySort(false)
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Satu baris SETIAP kod design (atau keterangan bila tiada kod) - gabung kuantiti SEMUA
     * cawangan/permintaan bagi design yg sama. Sertakan branch_demand_requests utk skop cawangan
     * & tarikh permintaan TERKINI (rujuk MAX(created_at) bawah).
     */
    protected function scopedQuery(): Builder
    {
        $query = BranchDemandRequestLine::query()
            ->join('branch_demand_requests', 'branch_demand_requests.id', '=', 'branch_demand_request_lines.branch_demand_request_id');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user && ! $user->isSuperAdmin() && ! $user->hasRole(['hq_reviewer', 'ceo'])) {
            $query->where('branch_demand_requests.store_code', $user->store_code ?? '__none__');
        }

        return $query
            ->selectRaw("COALESCE(NULLIF(TRIM(branch_demand_request_lines.internal_code), ''), CONCAT('desc:', TRIM(branch_demand_request_lines.item_desc))) as group_key")
            ->selectRaw('MIN(branch_demand_request_lines.id) as id')
            ->selectRaw('MAX(branch_demand_request_lines.internal_code) as internal_code')
            ->selectRaw('MAX(branch_demand_request_lines.item_desc) as item_desc')
            ->selectRaw('MAX(branch_demand_request_lines.category_name) as category_name')
            ->selectRaw('MAX(branch_demand_request_lines.image_url) as image_url')
            ->selectRaw('SUM(branch_demand_request_lines.qty_requested) as qty_requested_total')
            ->selectRaw('MAX(branch_demand_request_lines.created_at) as created_at')
            // Saiz/berat DITAIP BEBAS setiap kali cawangan hantar (bukan medan tetap) - satu kod
            // design boleh ada BEBERAPA nilai berlainan merentasi cawangan/hantaran, jadi
            // senaraikan SEMUA nilai unik (bukan MAX() - tu cuma pilih SATU secara sembarangan).
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(branch_demand_request_lines.size), '') ORDER BY TRIM(branch_demand_request_lines.size) SEPARATOR ', ') as sizes")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(branch_demand_request_lines.weight), '') ORDER BY TRIM(branch_demand_request_lines.weight) SEPARATOR ', ') as weights")
            ->groupBy('group_key');
    }

    /** Semua line SEBENAR (merentasi semua permintaan/cawangan) yg kongsi group_key $record ni. */
    protected function linesForGroup(BranchDemandRequestLine $record): Collection
    {
        $code = filled($record->internal_code) ? trim($record->internal_code) : null;

        return BranchDemandRequestLine::query()->with('request')
            ->when($code, fn($q) => $q->whereRaw('TRIM(internal_code) = ?', [$code]))
            ->when(! $code, fn($q) => $q
                ->where(fn($q2) => $q2->whereNull('internal_code')->orWhere('internal_code', ''))
                ->whereRaw('TRIM(item_desc) = ?', [trim((string) $record->item_desc)]))
            ->get();
    }

    protected function approvedLinesForGroup(BranchDemandRequestLine $record): Collection
    {
        return $this->linesForGroup($record)->where('line_status', BranchDemandRequestLine::STATUS_APPROVED);
    }

    /** Status progress kumpulan ni - satu nilai kalau SEMUA line APPROVED sama, null kalau
     * bercampur/tiada APPROVED (elak teka status, biar kosong). */
    protected function groupProgressFor(BranchDemandRequestLine $record): ?string
    {
        $statuses = $this->approvedLinesForGroup($record)->pluck('fulfillment_status')->unique();

        return $statuses->count() === 1 ? $statuses->first() : null;
    }
}
