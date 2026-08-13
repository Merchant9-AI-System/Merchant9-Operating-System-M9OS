<?php

namespace App\Support;

use App\Models\BranchDemandRequestLine;
use App\Models\InventoryMirror;
use App\Models\User;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Logik agregat per KOD DESIGN dikongsi antara AllBranchDemandLinesTable (widget BO) &
 * BranchDemandRequestLineExporter (eksport kertas) - SATU sumber kebenaran utk group_key,
 * pecahan cawangan, & nickname, supaya dua permukaan ni x drift. Instance BAHARU setiap
 * pengguna (widget/exporter masing2 pegang SATU instance) - cache dlm memori sekali sahaja
 * per instance (rujuk branchBreakdown()/nicknameLookup()).
 */
class BranchDemandLineSummary
{
    /** {group_key: [store_code => ['qty' => int, 'critical' => bool]]}. */
    protected ?SupportCollection $branchBreakdownCache = null;

    /** {internal_code (trim) => nickname}. */
    protected ?SupportCollection $nicknameCache = null;

    /** {group_key => stdClass{category_name, qty_requested_total, sizes, weights}}. */
    protected ?SupportCollection $designDetailsCache = null;

    /** Group key SAMA formula dgn scopedQuery() Filament (widget/exporter) - dikira semula drpd
     * $record agregat (bukan drpd DB terus, sbg $record cuma ada internal_code/item_desc drpd MAX()). */
    public function groupKeyFor(BranchDemandRequestLine $record): string
    {
        return filled($record->internal_code)
            ? trim($record->internal_code)
            : 'desc:'.trim((string) $record->item_desc);
    }

    /**
     * Kumpul ikut group_key + store_code, satu query utk SELURUH jadual (bukan satu query
     * setiap baris). Sertakan bendera "critical" per cawangan (ADA line stok_kritikal cawangan
     * tsb utk design ni) - satu design boleh kritikal utk SATU cawangan sahaja, bukan
     * keseluruhan (rujuk toggle "Kritikal" staf semasa hantar, per-hantaran bukan per-design).
     *
     * @return SupportCollection<string, array<string, array{qty: int, critical: bool}>> group_key => [store_code => [...]]
     */
    public function branchBreakdown(): SupportCollection
    {
        if ($this->branchBreakdownCache !== null) {
            return $this->branchBreakdownCache;
        }

        $query = DB::table('branch_demand_request_lines')
            ->join('branch_demand_requests', 'branch_demand_requests.id', '=', 'branch_demand_request_lines.branch_demand_request_id');

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
    public function branchBadgesFor(BranchDemandRequestLine $record): array
    {
        $breakdown = $this->branchBreakdown()->get($this->groupKeyFor($record), []);

        return collect($breakdown)
            ->map(fn ($info, $store) => "{$store}: {$info['qty']}")
            ->values()
            ->all();
    }

    /** true kalau cawangan (diparse drpd label "CAWANGAN: qty") ada line stok_kritikal utk design ni. */
    public function branchIsCritical(BranchDemandRequestLine $record, string $branchLabel): bool
    {
        $store = trim(explode(':', $branchLabel, 2)[0] ?? '');
        $breakdown = $this->branchBreakdown()->get($this->groupKeyFor($record), []);

        return $breakdown[$store]['critical'] ?? false;
    }

    /** Nama gaya/nickname tak formal drpd merchant9.com (rujuk App\Jobs\SyncMerchantNicknamesAndImages
     * & jemisys_inventory_mirror.nickname) - null kalau tiada internal_code (line SOURCE_WEB/
     * SOURCE_UPLOAD) atau design tsb blm disegerak/tiada padanan. */
    public function nicknameFor(BranchDemandRequestLine $record): ?string
    {
        if (blank($record->internal_code)) {
            return null;
        }

        return $this->nicknameLookup()->get(trim($record->internal_code));
    }

    /** Satu query kelompok utk SEMUA internal_code yg muncul dlm jadual ni, bukan satu per baris. */
    public function nicknameLookup(): SupportCollection
    {
        if ($this->nicknameCache !== null) {
            return $this->nicknameCache;
        }

        $codes = BranchDemandRequestLine::query()
            ->whereNotNull('internal_code')
            ->where('internal_code', '!=', '')
            ->distinct()
            ->pluck('internal_code')
            ->map(fn ($code) => trim($code))
            ->unique();

        return $this->nicknameCache = InventoryMirror::query()
            ->whereIn('InternalCode', $codes)
            ->get(['InternalCode', 'nickname'])
            ->mapWithKeys(fn ($m) => [trim((string) $m->InternalCode) => $m->nickname]);
    }

    /**
     * Butiran agregat SEBENAR per group_key (qty_requested_total/sizes/weights/category_name) -
     * dikira drpd query BEBAS/berasingan, BUKAN drpd atribut $record yg dihantar masuk.
     *
     * SEBAB WAJIB: Filament ExportAction (rujuk vendor Filament\Actions\Exports\Jobs\ExportCsv::
     * handle() - `$query->find($this->records)`) & InteractsWithTable::resolveTableRecord()
     * (`$query->find($key)`) DUA-DUA tambah WHERE id IN (...) PADA query GROUP BY asal utk
     * ambil SATU rekod semula. WHERE tsb terpakai SEBELUM GROUP BY (semantik SQL biasa), jadi
     * cuma SATU baris mentah (yg padan MIN(id) tsb) terselamat, lalu GROUP BY/SUM/GROUP_CONCAT
     * jadi kira drpd SATU baris tunggal sahaja - qty_requested_total dsb runtuh ke nilai SATU
     * cawangan, bukan jumlah SEBENAR merentasi semua cawangan (disahkan bug sebenar - "Diminta"
     * eksport tersilap tunjuk qty SATU cawangan). internal_code/item_desc (MAX() atas SATU baris
     * = nilai baris itu sendiri) x terjejas, jadi selamat terus baca drpd $record.
     *
     * @return SupportCollection<string, object>
     */
    public function designDetails(): SupportCollection
    {
        if ($this->designDetailsCache !== null) {
            return $this->designDetailsCache;
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
            ->selectRaw('MAX(branch_demand_request_lines.category_name) as category_name')
            ->selectRaw('SUM(branch_demand_request_lines.qty_requested) as qty_requested_total')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(branch_demand_request_lines.size), '') ORDER BY TRIM(branch_demand_request_lines.size) SEPARATOR ', ') as sizes")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(branch_demand_request_lines.weight), '') ORDER BY TRIM(branch_demand_request_lines.weight) SEPARATOR ', ') as weights")
            ->groupBy('group_key')
            ->get();

        return $this->designDetailsCache = $rows->keyBy('group_key');
    }

    public function designDetailFor(BranchDemandRequestLine $record): object
    {
        return $this->designDetails()->get($this->groupKeyFor($record)) ?? (object) [
            'category_name' => $record->category_name,
            'qty_requested_total' => (int) $record->qty_requested_total,
            'sizes' => $record->sizes ?? null,
            'weights' => $record->weights ?? null,
        ];
    }
}
