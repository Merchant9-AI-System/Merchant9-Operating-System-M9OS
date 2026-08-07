<?php

namespace App\Filament\Resources\BranchDemandRequests\Widgets;

use App\Models\BranchDemandRequestLine;
use App\Models\User;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
 */
class AllBranchDemandLinesTable extends TableWidget
{
    protected static ?string $heading = 'Ringkasan Ikut Kod Design';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    /** Cache dlm memori sekali per page load - {group_key: [store_code => qty]}. */
    protected ?SupportCollection $branchBreakdownCache = null;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->scopedQuery())
            ->columns([
                ImageColumn::make('image_url')->label('Gambar')->square()->imageSize(50)->url(fn (?string $state): ?string => $state, true)->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('internal_code')->label('Kod Design / Nama Item')
                    // getStateUsing() (bukan formatStateUsing()) sengaja - TextColumn::
                    // toEmbeddedHtml() semak KEKOSONGAN state MENTAH (getState(), drpd
                    // internal_code sebenar) SEBELUM formatStateUsing() sempat dipanggil, jadi
                    // fallback ke item_desc x akan pernah terpapar kalau internal_code=null bila
                    // guna formatStateUsing() - kena override STATE itu sendiri, bukan format lepas.
                    ->getStateUsing(fn (BranchDemandRequestLine $record) => filled($record->internal_code) ? $record->internal_code : $record->item_desc)
                    ->description(fn (BranchDemandRequestLine $record) => trim(
                        (filled($record->internal_code) ? (string) $record->item_desc : '')
                        .((filled($record->internal_code) && filled($record->category_name)) ? ' · ' : '')
                        .(string) ($record->category_name ?? '')
                    ), position: 'below')
                    ->searchable(['internal_code', 'item_desc'])
                    ->wrap(),
                TextColumn::make('branches')->label('Cawangan')
                    ->badge()
                    ->getStateUsing(fn (BranchDemandRequestLine $record) => $this->branchBadgesFor($record))
                    // Merah kalau CAWANGAN tsb (bukan design keseluruhan) ada line stok_kritikal
                    // - staf boleh tanda kritikal per-permintaan (rujuk BranchDemandEntryController::
                    // store()), jadi satu design boleh kritikal utk SATU cawangan tapi biasa utk yg lain.
                    ->color(fn (BranchDemandRequestLine $record, string $state) => $this->branchBadgeColorFor($record, $state)),
                TextColumn::make('qty_requested_total')->label('Diminta')->numeric()->sortable(),
                TextColumn::make('specifications')->label('Spesifikasi')
                    ->getStateUsing(fn (BranchDemandRequestLine $record) => filled($record->sizes) ? "Saiz: {$record->sizes}" : null)
                    ->description(fn (BranchDemandRequestLine $record) => filled($record->weights) ? "Berat: {$record->weights}g" : null)
                    ->placeholder('-'),
                SelectColumn::make('fulfillment_status')->label('Progress')
                    ->options(BranchDemandRequestLine::FULFILLMENT_LABELS)
                    ->selectablePlaceholder(true)
                    ->getStateUsing(fn (BranchDemandRequestLine $record) => $this->groupProgressFor($record))
                    ->disabled(fn (BranchDemandRequestLine $record) => ! Auth::user()?->can('Update:BranchDemandRequest'))
                        // || $this->approvedLinesForGroup($record)->isEmpty())
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
                    ->query(fn (Builder $query) => $query->havingRaw(
                        'SUM(CASE WHEN branch_demand_request_lines.fulfillment_status = ? THEN 1 ELSE 0 END) > 0',
                        [BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL]
                    )),
                SelectFilter::make('category_name')
                    ->label('Kategori')
                    ->options(fn () => BranchDemandRequestLine::query()
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

    /**
     * Sama skop cawangan dgn scopedQuery() - kumpul ikut group_key SAMA (internal_code/item_desc)
     * + store_code, satu query utk SELURUH jadual (bukan satu query setiap baris), disimpan dlm
     * $branchBreakdownCache supaya guna balik antara lajur Cawangan & Progress dlm SATU page load.
     * Sertakan bendera "critical" per cawangan (ADA line stok_kritikal cawangan tsb utk design
     * ni) - satu design boleh kritikal utk SATU cawangan sahaja, bukan keseluruhan (rujuk
     * toggle "Kritikal" staf semasa hantar, per-hantaran bukan per-design).
     *
     * @return SupportCollection<string, array<string, array{qty: int, critical: bool}>> group_key => [store_code => [...]]
     */
    protected function branchBreakdown(): SupportCollection
    {
        if ($this->branchBreakdownCache !== null) {
            return $this->branchBreakdownCache;
        }

        $query = DB::table('branch_demand_request_lines')
            ->join('branch_demand_requests', 'branch_demand_requests.id', '=', 'branch_demand_request_lines.branch_demand_request_id');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user && ! $user->isSuperAdmin() && ! $user->hasRole(['hq_reviewer', 'ceo'])) {
            $query->where('branch_demand_requests.store_code', $user->store_code ?? '__none__');
        }

        $rows = $query
            ->selectRaw("COALESCE(NULLIF(TRIM(branch_demand_request_lines.internal_code), ''), CONCAT('desc:', TRIM(branch_demand_request_lines.item_desc))) as group_key")
            ->selectRaw('TRIM(branch_demand_requests.store_code) as store_code')
            ->selectRaw('SUM(branch_demand_request_lines.qty_requested) as qty')
            ->selectRaw(
                'SUM(CASE WHEN branch_demand_request_lines.fulfillment_status = ? THEN 1 ELSE 0 END) as critical_count',
                [BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL]
            )
            ->groupBy('group_key', 'store_code')
            ->get();

        return $this->branchBreakdownCache = $rows->groupBy('group_key')
            ->map(fn ($rowsForGroup) => $rowsForGroup->mapWithKeys(fn ($row) => [
                $row->store_code => ['qty' => (int) $row->qty, 'critical' => $row->critical_count > 0],
            ])->all());
    }

    /** @return array<int, string> senarai "CAWANGAN: qty" utk SEMUA cawangan yg minta design ni. */
    protected function branchBadgesFor(BranchDemandRequestLine $record): array
    {
        $breakdown = $this->branchBreakdown()->get($this->groupKeyFor($record), []);

        return collect($breakdown)
            ->map(fn ($info, $store) => "{$store}: {$info['qty']}")
            ->values()
            ->all();
    }

    /** Merah kalau cawangan (diparse drpd label "CAWANGAN: qty") ada line stok_kritikal utk
     * design ni, kelabu (lalai badge) kalau tak. */
    protected function branchBadgeColorFor(BranchDemandRequestLine $record, string $state): string
    {
        $store = trim(explode(':', $state, 2)[0] ?? '');
        $breakdown = $this->branchBreakdown()->get($this->groupKeyFor($record), []);

        return ($breakdown[$store]['critical'] ?? false) ? 'danger' : 'gray';
    }

    /** Group key SAMA formula dgn scopedQuery()/branchBreakdown() - dikira semula drpd $record
     * agregat (bukan drpd DB terus, sbg $record cuma ada internal_code/item_desc drpd MAX()). */
    protected function groupKeyFor(BranchDemandRequestLine $record): string
    {
        return filled($record->internal_code)
            ? trim($record->internal_code)
            : 'desc:'.trim((string) $record->item_desc);
    }

    /** Semua line SEBENAR (merentasi semua permintaan/cawangan) yg kongsi group_key $record ni. */
    protected function linesForGroup(BranchDemandRequestLine $record): Collection
    {
        $code = filled($record->internal_code) ? trim($record->internal_code) : null;

        return BranchDemandRequestLine::query()->with('request')
            ->when($code, fn ($q) => $q->whereRaw('TRIM(internal_code) = ?', [$code]))
            ->when(! $code, fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNull('internal_code')->orWhere('internal_code', ''))
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
