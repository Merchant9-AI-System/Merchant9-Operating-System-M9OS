<?php

namespace App\Support;

use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\StockTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Data & peraturan utk halaman Back Office Actions (rujuk BackOfficeActionsController): senarai
 * line permintaan cawangan yg belum selesai + cadangan Rearrange/Restock setiap baris.
 *
 * - Stok SEMUA cawangan (kecuali Security, termasuk yg stok 0) disemak utk setiap design.
 * - REARRANGE: cawangan LAIN ada stok >= BranchDemandAllocationRecommender::DONOR_MIN_STOCK
 *   (baki DONOR_KEEP_STOCK kekal simpanan donor; HQ - tiada jualan - semua stok boleh dipindah) - peraturan SAMA dgn Module D, tapi line
 *   Pending turut dikira (Module D cuma Approved) atas permintaan BO supaya cadangan nampak
 *   SEBELUM leader luluskan.
 * - RESTOCK: baki permintaan yg TAK dapat dipenuhi stok lebihan rangkaian (allocate()) - boleh
 *   serentak dgn Rearrange (ACTION_BOTH) bila lebihan cuma cukup sebahagian, atau Restock
 *   sahaja bila semua cawangan stok rendah. Papar juga permintaan cawangan LAIN, stok semasa
 *   & jualan 7/30 hari terkini (cermin jemisys_inventory_mirror SalesDate) utk BO nilai.
 *
 * Jualan 7 & 30 hari dikira SEKALI (satu query, SUM(CASE...)) - pilihan tempoh di UI sekadar
 * tukar paparan, tak perlu request semula.
 */
class BackOfficeActionsAdvisor
{
    public const ACTION_REARRANGE = 'rearrange';

    public const ACTION_RESTOCK = 'restock';

    /** Rearrange sebahagian sahaja mampu penuhi - baki perlu Restock. */
    public const ACTION_BOTH = 'both';

    /** Paras stok sasaran setiap cawangan jualan utk cadangan kuantiti Restock (lalai - leader BO boleh ubah). */
    public const RESTOCK_TARGET_STOCK = 3;

    /** Status line yg masih dikira sbg permintaan utk kuantiti Restock: belum diputuskan ATAU sudah masuk
     * senarai Restock (Order). Dah Order/Restock/Delivery tak dikira lagi. @var array<int, string> */
    public const RESTOCK_REQUEST_STATUSES = [
        BranchDemandRequestLine::FULFILLMENT_ORDER,
        BranchDemandRequestLine::FULFILLMENT_REARRANGE,
        BranchDemandRequestLine::FULFILLMENT_REQUESTED,
        BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL,
        BranchDemandRequestLine::FULFILLMENT_SPECIAL_REQUEST,
        BranchDemandRequestLine::FULFILLMENT_LISTED_NOTED,
    ];

    /** Cawangan yg stoknya TIDAK dikira langsung (bukan cawangan jualan - peti simpanan). */
    public const EXCLUDED_STORES = ['SECURITY'];

    /** Peringkat yg BELUM diputuskan BO - cuma line di peringkat ni dapat badge cadangan. Line yg
     * dah Dah Order/Restock/Delivery, Order atau Item Not Available tak perlu cadangan lagi (BO
     * dah bertindak), walaupun donor tetap dikira utk dropdown status. Rearrange SENGAJA kekal
     * di sini: transfer yg dicipta tolak baki line, jadi baki yg masih ada selepas Rearrange =
     * kekurangan yg perlu Restock (line dgn baki 0 tak dapat badge sebab outstanding < 1).
     *
     * @var array<int, string> */
    public const ACTIONABLE_STATUSES = [
        BranchDemandRequestLine::FULFILLMENT_REARRANGE,
        BranchDemandRequestLine::FULFILLMENT_REQUESTED,
        BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL,
        BranchDemandRequestLine::FULFILLMENT_SPECIAL_REQUEST,
        BranchDemandRequestLine::FULFILLMENT_LISTED_NOTED,
    ];

    /** @return array{lines: array<int, array<string, mixed>>, designs: array<string, array<string, mixed>>} */
    public function board(): array
    {
        return $this->assemble($this->loadLines());
    }

    /**
     * Pratonton cadangan utk line TANPA kod design (web/upload) bila leader BO pilih satu kod -
     * SEMUA line yg dipilih (satu kumpulan nama sama merentas cawangan) diperlakukan seolah-olah
     * ada kod tsb (dlm ingatan sahaja, TIDAK disimpan) & dikumpul bersama line cawangan lain yg
     * sudah ada kod yg sama.
     *
     * @param  Collection<int, BranchDemandRequestLine>  $targets
     * @return array{lines: array<int, array<string, mixed>>, design: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function previewFor(Collection $targets, string $code): array
    {
        $code = $this->assertDesignCodeExists($code);
        $targetIds = $targets->pluck('id')->all();

        foreach ($targets as $line) {
            $line->loadMissing('request');
            $line->setAttribute('internal_code', $code);
        }

        $group = $this->loadLines()
            ->reject(fn (BranchDemandRequestLine $l) => in_array($l->id, $targetIds, true) || $this->codeOf($l) !== $code)
            ->concat($targets);

        $board = $this->assemble($group);

        return [
            'lines' => collect($board['lines'])->whereIn('id', $targetIds)->values()->all(),
            'design' => $board['designs'][$code],
        ];
    }

    /** Sahkan kod wujud dlm cermin inventori (vendor sah) - pulang kod yg ditrim. */
    public function assertDesignCodeExists(string $code): string
    {
        $code = trim($code);

        if ($code === '' || ! InventoryPiece::query()->realVendor()->where('InternalCode', $code)->exists()) {
            $this->fail("Kod design \"{$code}\" tiada dalam inventori.");
        }

        return $code;
    }

    /** Simpan kod design pilihan BO pada line yg belum ada kod. */
    public function assignDesignCode(BranchDemandRequestLine $line, string $code): void
    {
        $code = $this->assertDesignCodeExists($code);

        if (filled($line->internal_code) && trim((string) $line->internal_code) !== $code) {
            $this->fail('Line '.trim((string) $line->internal_code).' sudah ada kod design - tak boleh ditukar di sini.');
        }

        $line->update(['internal_code' => $code]);
    }

    /** Bilangan calon minimum (nickname) sebelum Description tak perlu dijadikan sandaran. */
    public const CANDIDATE_POOL_MIN = 30;

    /**
     * Calon kod design utk line tanpa kod: padan nickname (merchant9.com) ATAU Description
     * jemisys_inventory_mirror (FULLTEXT) dgn nama line / carian BO, disusun ikut kemiripan nama.
     * Dibahagi kepada halaman (butang "Muat Lagi") - kolam calon dicache sebentar supaya halaman
     * seterusnya tak ulang carian FULLTEXT.
     *
     * @return array{candidates: array<int, array{internal_code: string, description: ?string, nickname: ?string, category_code: ?string, size: ?string, weight: ?float, image_url: ?string, total_stock: int, match: string, score: int}>, has_more: bool, total: int}
     */
    public function designCandidates(string $term, int $page = 1, int $perPage = 10): array
    {
        $pool = $this->candidatePool($term);
        $offset = max(0, ($page - 1) * $perPage);
        $slice = array_slice($pool, $offset, $perPage);
        $stock = $this->totalStockByCode(array_column($slice, 'internal_code'));

        return [
            'candidates' => array_map(fn (array $c) => $c + ['total_stock' => $stock[$c['internal_code']] ?? 0], $slice),
            'has_more' => count($pool) > $offset + $perPage,
            'total' => count($pool),
        ];
    }

    /**
     * Kolam penuh calon (tanpa stok) disusun ikut kemiripan - dicache 5 minit per istilah carian.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function candidatePool(string $term): array
    {
        return Cache::remember('bo_design_candidates:'.md5(mb_strtolower(trim($term))), now()->addMinutes(5), fn () => $this->buildCandidatePool($term));
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildCandidatePool(string $term): array
    {
        $words = collect(preg_split('/[^\p{L}0-9]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $w) => mb_strlen($w) < 3 || preg_match('/\d/', $w) === 1 || in_array($w, ['dimensi', 'cm'], true))
            ->unique()
            ->values();

        $normalised = $words->implode(' ');

        if ($normalised === '') {
            return [];
        }

        $found = collect();

        // Tanpa GROUP BY: perkataan umum (cth. "cincin") padan puluhan ribu piece & GROUP BY atasnya
        // ambil 15s+. FULLTEXT sudah pulang baris ikut relevans, jadi ambil N baris teratas sahaja
        // lalu dedupe per kod di PHP. Description cuma jadi sandaran bila nickname tak cukup padanan
        // (sebelum sync nickname siap / design tiada di storefront).
        foreach (['nickname' => 'nickname', 'description' => 'Description'] as $match => $column) {
            if ($match === 'description' && $found->count() >= self::CANDIDATE_POOL_MIN) {
                break;
            }

            $rows = InventoryPiece::query()
                ->realVendor()
                ->whereRaw("MATCH({$column}) AGAINST (? IN NATURAL LANGUAGE MODE)", [$normalised])
                ->select(['InternalCode', 'nickname', 'Description', 'CategoryCode', 'JewelSize', 'GoldWeight', 'image_url'])
                ->limit(400)
                ->toBase()
                ->get();

            foreach ($rows as $r) {
                $code = trim((string) $r->InternalCode);

                if ($found->has($code)) {
                    continue;
                }

                $found[$code] = [
                    'internal_code' => $code,
                    'description' => filled($r->Description) ? trim((string) $r->Description) : null,
                    'nickname' => filled($r->nickname) ? trim((string) $r->nickname) : null,
                    'category_code' => filled($r->CategoryCode) ? trim((string) $r->CategoryCode) : null,
                    'size' => filled($r->JewelSize) ? trim((string) $r->JewelSize) : null,
                    'weight' => $r->GoldWeight !== null ? (float) $r->GoldWeight : null,
                    'image_url' => filled($r->image_url) ? $r->image_url : null,
                    'match' => $match,
                    'score' => $this->similarity($term, $match === 'nickname' ? $r->nickname : $r->Description),
                ];
            }
        }

        return $found->sortByDesc('score')->values()->all();
    }

    /** Kemiripan nama 0-100 (similar_text pada huruf/nombor sahaja, tak sensitif huruf besar). */
    protected function similarity(string $a, ?string $b): int
    {
        $clean = fn (?string $v) => trim((string) preg_replace('/[^\p{L}0-9]+/u', ' ', mb_strtolower((string) $v)));
        similar_text($clean($a), $clean($b), $percent);

        return (int) round($percent);
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, int>
     */
    protected function totalStockByCode(array $codes): array
    {
        return collect($this->stockByCode($codes))->map(fn (array $stores) => (int) array_sum($stores))->all();
    }

    /**
     * @param  Collection<int, BranchDemandRequestLine>  $lines
     * @return array{lines: array<int, array<string, mixed>>, designs: array<string, array<string, mixed>>}
     */
    protected function assemble(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return ['lines' => [], 'designs' => []];
        }

        $codes = $lines->pluck('internal_code')->map(fn ($c) => trim((string) $c))->filter()->unique()->values()->all();
        $reserved = $this->reservedByCode($codes);
        $stock = $this->stockByCode($codes, $reserved);
        $sales = $this->salesByCode($codes);
        $branches = $this->branchCodes();
        $summary = new BranchDemandLineSummary;

        $designs = [];
        $allocations = [];

        foreach ($lines->groupBy(fn (BranchDemandRequestLine $line) => $summary->groupKeyFor($line)) as $key => $group) {
            $code = $this->codeOf($group->first());
            $stores = $code ? ($stock[$code] ?? []) : [];
            $allocations[$key] = $this->allocate($group, $stores);
            $designs[$key] = $this->design($group, $stores, $code ? ($reserved[$code] ?? []) : [], $code ? ($sales[$code] ?? []) : [], $branches, $allocations[$key]);
        }

        $rows = $lines->map(function (BranchDemandRequestLine $line) use ($stock, $summary, $allocations) {
            $own = $this->storeOf($line);
            $code = $this->codeOf($line);
            $outstanding = $this->outstanding($line);
            $stores = $code ? ($stock[$code] ?? []) : [];
            $donors = $code && $outstanding > 0 ? $this->donors($stores, $own, $outstanding) : [];
            $alloc = $allocations[$summary->groupKeyFor($line)][$line->id] ?? ['rearrange' => 0, 'restock' => 0, 'moves' => []];

            return [
                'id' => $line->id,
                'design_key' => $summary->groupKeyFor($line),
                'request_number' => $line->request->request_number,
                'store_code' => $own,
                'internal_code' => $code,
                'item_desc' => $line->item_desc,
                'category_name' => $line->category_name,
                'image_url' => $line->image_url,
                'nickname' => $summary->nicknameFor($line),
                'size' => $line->size,
                'weight' => $line->weight,
                'remark' => $line->remark,
                'qty_requested' => (int) $line->qty_requested,
                'qty_outstanding' => $outstanding,
                'fulfillment_status' => $line->fulfillment_status,
                'is_critical' => $line->fulfillment_status === BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL,
                'own_stock' => (int) ($stores[$own] ?? 0),
                'action' => match (true) {
                    $code === null, ! $this->isActionable($line, $outstanding) => null,
                    $alloc['rearrange'] > 0 && $alloc['restock'] > 0 => self::ACTION_BOTH,
                    $alloc['rearrange'] > 0 => self::ACTION_REARRANGE,
                    default => self::ACTION_RESTOCK,
                },
                'rearrange_qty' => $alloc['rearrange'],
                'restock_qty' => $alloc['restock'],
                'moves' => $alloc['moves'],
                'donors' => $donors,
            ];
        })->values();

        return ['lines' => $rows->all(), 'designs' => $designs];
    }

    /**
     * Pelan Rearrange utk SATU line - dipakai semasa Simpan. Tak percaya nilai klien: derma/qty
     * disahkan semula drpd stok semasa (donor mesti layak, qty 1..max_qty).
     *
     * @return array{from_store: string, to_store: string, qty: int}
     *
     * @throws ValidationException
     */
    public function rearrangePlan(BranchDemandRequestLine $line, ?string $fromStore = null, ?int $qty = null): array
    {
        $line->loadMissing('request');
        $own = $this->storeOf($line);
        $code = $this->codeOf($line);
        $label = ($code ?? trim((string) $line->item_desc))." ({$own})";
        $outstanding = $this->outstanding($line);

        if ($code === null) {
            $this->fail("{$label}: tiada kod design - Rearrange tak boleh dicipta, padankan ke stok sebenar dulu.");
        }

        if ($outstanding < 1) {
            $this->fail("{$label}: tiada baki permintaan utk dipindahkan.");
        }

        $donors = collect($this->donors($this->stockByCode([$code])[$code] ?? [], $own, $outstanding));

        if ($donors->isEmpty()) {
            $this->fail("{$label}: tiada cawangan lain ada stok cukup (min ".BranchDemandAllocationRecommender::DONOR_MIN_STOCK.' unit; HQ min 1 unit) utk Rearrange.');
        }

        $donor = filled($fromStore) ? $donors->firstWhere('store_code', trim($fromStore)) : $donors->first();

        if (! $donor) {
            $this->fail("{$label}: cawangan ".trim((string) $fromStore).' bukan donor yang layak.');
        }

        $qty ??= $donor['max_qty'];

        if ($qty < 1 || $qty > $donor['max_qty']) {
            $this->fail("{$label}: kuantiti Rearrange mesti antara 1 hingga {$donor['max_qty']}.");
        }

        return ['from_store' => $donor['store_code'], 'to_store' => $own, 'qty' => $qty];
    }

    /** @param  array{from_store: string, to_store: string, qty: int}  $plan */
    public function createRearrangeTransfer(BranchDemandRequestLine $line, array $plan, string $actor): StockTransfer
    {
        return StockTransfer::create([
            'branch_demand_request_line_id' => $line->id,
            'internal_code' => $this->codeOf($line),
            'item_desc' => $line->item_desc,
            'category_code' => $line->category_name,
            'from_store' => $plan['from_store'],
            'to_store' => $plan['to_store'],
            'qty' => $plan['qty'],
            'requested_by' => $actor,
        ]);
    }

    /** Baki belum dipenuhi - dikira sendiri (bukan accessor qty_outstanding yg pulang 0 utk
     * line bukan Approved), tolak transfer aktif (kecuali Cancelled). */
    public function outstanding(BranchDemandRequestLine $line): int
    {
        $fulfilled = array_key_exists('fulfilled_sum', $line->getAttributes())
            ? (int) $line->getAttribute('fulfilled_sum')
            : (int) $line->transfers()->where('status', '!=', StockTransfer::STATUS_CANCELLED)->sum('qty');

        return max(0, (int) ($line->qty_approved ?? $line->qty_requested) - $fulfilled);
    }

    /** @return Collection<int, BranchDemandRequestLine> */
    protected function loadLines(): Collection
    {
        return BranchDemandRequestLine::query()
            ->with('request')
            ->withSum(['transfers as fulfilled_sum' => fn ($q) => $q->where('status', '!=', StockTransfer::STATUS_CANCELLED)], 'qty')
            ->whereNull('done_at')
            ->where('line_status', '!=', BranchDemandRequestLine::STATUS_REJECTED)
            ->whereHas('request', fn ($q) => $q->where('status', '!=', BranchDemandRequest::STATUS_CANCELLED))
            ->get()
            ->sortBy([
                fn ($a, $b) => strcasecmp($this->sortKey($a), $this->sortKey($b)),
                fn ($a, $b) => strcasecmp($this->storeOf($a), $this->storeOf($b)),
            ])
            ->values();
    }

    /** Line yg BO belum putuskan & masih ada baki - sahaja layak dapat cadangan & menggunakan stok lebihan. */
    protected function isActionable(BranchDemandRequestLine $line, int $outstanding): bool
    {
        return $outstanding > 0 && in_array($line->fulfillment_status, self::ACTIONABLE_STATUSES, true);
    }

    /**
     * Agihan stok lebihan rangkaian (Rearrange) kpd line SATU design, baki yg tak dapat dipenuhi =
     * Restock. Line Kritikal dahulu, kemudian yg paling lama (id kecil) - elak satu donor
     * "dijanjikan" dua kali. Stok lebihan donor = spareOf(), cawangan peminta tak boleh jadi donor utk dirinya sendiri.
     *
     * @param  Collection<int, BranchDemandRequestLine>  $group
     * @param  array<string, int>  $stores  store_code => stok bersih (tanpa Security)
     * @return array<int, array{rearrange: int, restock: int, moves: array<int, array{from_store: string, qty: int}>}> line id => agihan
     */
    protected function allocate(Collection $group, array $stores): array
    {
        $spare = collect($stores)
            ->map(fn (int $s, string $store) => $this->spareOf($store, $s))
            ->filter(fn (int $s) => $s > 0)
            ->all();

        $result = [];

        $ordered = $group->sortBy([
            fn ($a, $b) => ($b->fulfillment_status === BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL) <=> ($a->fulfillment_status === BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL),
            fn ($a, $b) => $a->id <=> $b->id,
        ]);

        foreach ($ordered as $line) {
            $need = $this->outstanding($line);
            $moved = 0;
            $moves = [];

            if ($this->codeOf($line) !== null && $this->isActionable($line, $need)) {
                $own = $this->storeOf($line);
                arsort($spare);

                foreach ($spare as $store => $available) {
                    if ($store === $own || $available < 1 || $moved >= $need) {
                        continue;
                    }

                    $take = min($need - $moved, $available);
                    $spare[$store] -= $take;
                    $moved += $take;
                    $moves[] = ['from_store' => (string) $store, 'qty' => $take];
                }

                $result[$line->id] = ['rearrange' => $moved, 'restock' => $need - $moved, 'moves' => $moves];

                continue;
            }

            $result[$line->id] = ['rearrange' => 0, 'restock' => 0, 'moves' => []];
        }

        return $result;
    }

    /**
     * @param  Collection<int, BranchDemandRequestLine>  $group
     * @param  array<string, int>  $stores  store_code => stok bersih
     * @param  array<string, int>  $reserved  store_code => unit sudah ditempah transfer Requested
     * @param  array<string, array{sold_7d: int, sold_30d: int}>  $sales
     * @param  array<int, string>  $branches  semua cawangan jualan (tanpa Security)
     * @param  array<int, array{rearrange: int, restock: int, moves: array<int, array{from_store: string, qty: int}>}>  $allocation
     * @return array<string, mixed>
     */
    protected function design(Collection $group, array $stores, array $reserved, array $sales, array $branches, array $allocation): array
    {
        $code = $this->codeOf($group->first());

        $demand = $group
            ->map(fn (BranchDemandRequestLine $l) => ['store_code' => $this->storeOf($l), 'qty' => $this->outstanding($l)])
            ->filter(fn (array $d) => $d['qty'] > 0)
            ->groupBy('store_code')
            ->map(fn (Collection $rows, string $store) => ['store_code' => $store, 'qty' => $rows->sum('qty')])
            ->sortByDesc('qty')
            ->values();

        $spare = (int) collect($stores)->sum(fn (int $s, string $store) => $this->spareOf($store, $s));

        // Semua cawangan (termasuk stok 0) - supaya BO nampak "semua cawangan memang takde/sikit",
        // bukan sekadar cawangan yg ada stok.
        $stockRows = collect($branches)
            ->merge(array_keys($stores))
            ->reject(fn (string $s) => $this->isExcludedStore($s))
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $store) => [
                'store_code' => $store,
                'stock' => (int) ($stores[$store] ?? 0),
                'reserved' => (int) ($reserved[$store] ?? 0),
            ]);

        return [
            'internal_code' => $code,
            'demand' => $demand->all(),
            'total_outstanding' => (int) $demand->sum('qty'),
            'stock' => $stockRows->all(),
            'total_stock' => (int) array_sum($stores),
            'spare_stock' => $spare,
            'network_low' => $code !== null && $spare === 0,
            'sales' => collect($sales)->map(fn (array $s, string $store) => ['store_code' => $store, ...$s])->sortBy('store_code')->values()->all(),
            'sold_7d' => (int) collect($sales)->sum('sold_7d'),
            'sold_30d' => (int) collect($sales)->sum('sold_30d'),
            'rearrange_qty' => $code ? (int) collect($allocation)->sum('rearrange') : null,
            'restock_qty' => $code ? (int) collect($allocation)->sum('restock') : null,
            'restock_plan' => $code ? $this->restockPlan($stores, $branches, $this->requestedByStore($group)) : null,
        ];
    }

    /**
     * Permintaan (baki) setiap cawangan utk kuantiti Restock - cuma line yg belum selesai di sisi BO.
     *
     * @param  Collection<int, BranchDemandRequestLine>  $group
     * @return array<string, int> store_code => unit
     */
    protected function requestedByStore(Collection $group): array
    {
        return $group
            ->filter(fn (BranchDemandRequestLine $l) => in_array($l->fulfillment_status, self::RESTOCK_REQUEST_STATUSES, true))
            ->groupBy(fn (BranchDemandRequestLine $l) => $this->storeOf($l))
            ->map(fn (Collection $rows) => (int) $rows->sum(fn (BranchDemandRequestLine $l) => $this->outstanding($l)))
            ->filter(fn (int $qty) => $qty > 0)
            ->all();
    }

    /**
     * Cadangan kuantiti Restock: paras RESTOCK_TARGET_STOCK (3) setiap cawangan jualan. Keperluan
     * cawangan = lebih besar antara (paras - stok) dan permintaan cawangan itu. Ditolak lebihan
     * sedia ada (stok HQ & stok melebihi paras di cawangan yg TAK request) kerana boleh di-Rearrange
     * dulu. Contoh: 3 cawangan stok 0 + 2 cawangan stok 1 => 3x3 + 2x2 = 13. Leader BO bebas
     * tukar nombor ini.
     *
     * @param  array<string, int>  $stores  store_code => stok bersih (Security tiada)
     * @param  array<int, string>  $branches  semua cawangan jualan
     * @param  array<string, int>  $requested  store_code => unit diminta
     * @return array{target: int, branches: array<int, array{store_code: string, stock: int, requested: int, need: int}>, surplus: int, need_total: int, suggested_qty: int}
     */
    public function restockPlan(array $stores, array $branches, array $requested): array
    {
        $target = self::RESTOCK_TARGET_STOCK;
        $names = collect($branches)->merge(array_keys($requested))->reject(fn (string $b) => $this->isExcludedStore($b))->unique()->sort()->values();

        $rows = $names->map(function (string $store) use ($stores, $requested, $target) {
            $stock = (int) ($stores[$store] ?? 0);
            $asked = (int) ($requested[$store] ?? 0);

            return ['store_code' => $store, 'stock' => $stock, 'requested' => $asked, 'need' => max(max(0, $target - $stock), $asked)];
        });

        $surplus = (int) $rows
            ->filter(fn (array $r) => $r['requested'] === 0)
            ->sum(fn (array $r) => max(0, $r['stock'] - $target));

        // Stok di luar senarai cawangan jualan (cth. HQ - tiada jualan) sepenuhnya boleh diagih.
        $surplus += (int) collect($stores)
            ->reject(fn (int $s, string $store) => $names->contains($store))
            ->sum();

        $needTotal = (int) $rows->sum('need');

        return [
            'target' => $target,
            'branches' => $rows->all(),
            'surplus' => $surplus,
            'need_total' => $needTotal,
            'suggested_qty' => max(0, $needTotal - $surplus),
        ];
    }

    /** Cadangan kuantiti Restock utk SATU kod (dipakai bila line baharu masuk senarai Restock semasa Simpan). */
    public function suggestedRestockQty(string $code): int
    {
        $lines = $this->loadLines()->filter(fn (BranchDemandRequestLine $l) => $this->codeOf($l) === $code);

        return $this->restockPlan($this->stockByCode([$code])[$code] ?? [], $this->branchCodes(), $this->requestedByStore($lines))['suggested_qty'];
    }

    /**
     * Unit yg boleh didermakan oleh satu cawangan: cawangan jualan perlu stok >= DONOR_MIN_STOCK dan
     * DONOR_KEEP_STOCK kekal simpanan. HQ tiada jualan - tiada simpanan diperlukan, SEMUA stok
     * boleh dipindahkan (jadi Restock tak perlu dicadangkan selagi HQ masih ada stok).
     */
    protected function spareOf(string $store, int $stock): int
    {
        if (strtoupper(trim($store)) === 'HQ') {
            return max(0, $stock);
        }

        return $stock >= BranchDemandAllocationRecommender::DONOR_MIN_STOCK
            ? $stock - BranchDemandAllocationRecommender::DONOR_KEEP_STOCK
            : 0;
    }

    public function isExcludedStore(string $store): bool
    {
        return in_array(strtoupper(trim($store)), self::EXCLUDED_STORES, true);
    }

    /**
     * Semua cawangan jualan (HQ & Security bukan cawangan runcit - selaras borang permintaan cawangan).
     *
     * @return array<int, string>
     */
    public function branchCodes(): array
    {
        return Store::query()->orderBy('StoreCode')->get()
            ->map(fn ($s) => trim((string) $s->StoreCode))
            ->reject(fn (string $s) => $s === '' || strtoupper($s) === 'HQ' || strtoupper($s) === 'WEB' || $this->isExcludedStore($s))
            ->values()
            ->all();
    }

    /**
     * Unit yg sudah "ditempah" transfer berstatus Requested di cawangan asal (stok masih di mirror
     * tapi dah dijanjikan) - ditolak drpd stok donor supaya tak dijanjikan dua kali. In Transit
     * tak ditolak: tak pasti sama ada mirror dah keluarkan unit tsb.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<string, int>> design => [store_code => unit]
     */
    protected function reservedByCode(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return StockTransfer::query()
            ->whereIn('internal_code', $codes)
            ->where('status', StockTransfer::STATUS_REQUESTED)
            ->selectRaw('internal_code, from_store, SUM(qty) as reserved')
            ->groupBy('internal_code', 'from_store')
            ->toBase()
            ->get()
            ->groupBy(fn ($r) => trim((string) $r->internal_code))
            ->map(fn (Collection $rows) => $rows
                ->mapWithKeys(fn ($r) => [trim((string) $r->from_store) => (int) $r->reserved])
                ->all())
            ->all();
    }

    /**
     * @param  array<string, int>  $stores  store_code => stok
     * @return array<int, array{store_code: string, stock: int, max_qty: int, recommended: bool}>
     */
    protected function donors(array $stores, string $ownStore, int $outstanding): array
    {
        return collect($stores)
            ->reject(fn (int $stock, string $store) => $store === $ownStore)
            ->filter(fn (int $stock, string $store) => $this->spareOf($store, $stock) > 0)
            ->sortDesc()
            ->map(fn (int $stock, string $store) => [
                'store_code' => $store,
                'stock' => $stock,
                'max_qty' => min($outstanding, $this->spareOf($store, $stock)),
                'recommended' => false,
            ])
            ->values()
            ->map(fn (array $d, int $i) => ['recommended' => $i === 0] + $d)
            ->all();
    }

    /**
     * Stok bersih per design per cawangan (Security dikecualikan; tolak unit yg sudah ditempah
     * transfer Requested). Cawangan stok 0 tak disertakan - dipenuhkan semula di design().
     *
     * @param  array<int, string>  $codes
     * @param  array<string, array<string, int>>|null  $reserved  hasil reservedByCode() (dikira sendiri jika null)
     * @return array<string, array<string, int>> design => [store_code => stok]
     */
    public function stockByCode(array $codes, ?array $reserved = null): array
    {
        if ($codes === []) {
            return [];
        }

        $reserved ??= $this->reservedByCode($codes);

        return InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->whereNotIn('StoreCode', ['SECURITY', 'security'])
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, StoreCode, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode', 'StoreCode')
            ->toBase()
            ->get()
            ->reject(fn ($r) => $this->isExcludedStore((string) $r->StoreCode))
            ->groupBy(fn ($r) => trim((string) $r->InternalCode))
            ->map(fn (Collection $rows, string $code) => $rows
                ->mapWithKeys(fn ($r) => [trim((string) $r->StoreCode) => max(0, (int) $r->stock - (int) ($reserved[$code][trim((string) $r->StoreCode)] ?? 0))])
                ->filter(fn (int $s) => $s > 0)
                ->all())
            ->all();
    }

    /**
     * Jualan 7 & 30 hari terkini (piece dgn SalesDate dlm tempoh) per design per cawangan fizikal.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<string, array{sold_7d: int, sold_30d: int}>>
     */
    public function salesByCode(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return InventoryPiece::query()
            ->physicalStore()
            ->whereNotIn('StoreCode', ['SECURITY', 'security'])
            ->whereIn('InternalCode', $codes)
            ->where('SalesDate', '>=', now()->subDays(30)->startOfDay())
            ->selectRaw('InternalCode, StoreCode, SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as sold_7d, COUNT(*) as sold_30d', [now()->subDays(7)->startOfDay()])
            ->groupBy('InternalCode', 'StoreCode')
            ->get()
            ->reject(fn ($r) => $this->isExcludedStore((string) $r->StoreCode))
            ->groupBy(fn ($r) => trim((string) $r->InternalCode))
            ->map(fn (Collection $rows) => $rows
                ->mapWithKeys(fn ($r) => [trim((string) $r->StoreCode) => ['sold_7d' => (int) $r->sold_7d, 'sold_30d' => (int) $r->sold_30d]])
                ->all())
            ->all();
    }

    protected function storeOf(BranchDemandRequestLine $line): string
    {
        return trim((string) $line->request->store_code);
    }

    protected function codeOf(BranchDemandRequestLine $line): ?string
    {
        return filled($line->internal_code) ? trim((string) $line->internal_code) : null;
    }

    protected function sortKey(BranchDemandRequestLine $line): string
    {
        return $this->codeOf($line) ?? trim((string) $line->item_desc);
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['changes' => $message]);
    }
}
