<?php

namespace App\Support;

use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\InventoryPiece;
use App\Models\RestockListItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Data Senarai Restock leader BO (rujuk halaman Back Office Actions) utk paparan, Excel & cetakan:
 * setiap design dlm senarai (RestockListItem draf) + cawangan yg request (saiz/berat/qty drpd line
 * berstatus Order - cawangan lain yg stok rendah tapi TAK request tiada maklumat saiz/berat),
 * stok semua cawangan kecuali Security, jualan 7/30 hari, & (super_admin sahaja) 3 supplier teratas
 * berdasarkan JUALAN KESELURUHAN design tsb (VendorCode piece yg terjual) - KOD supplier sahaja,
 * nama penuh supplier sengaja tidak didedahkan.
 */
class RestockListBuilder
{
    /** Bilangan supplier teratas dipaparkan setiap design. */
    public const TOP_SUPPLIERS = 3;

    /** Tempoh (hari) bagi "qty terjual" di sebelah jualan terkini. */
    public const LATEST_SALES_DAYS = 30;

    /** Tempoh jualan (hari) utk ranking supplier: lalai 30, maksimum 2 tahun. */
    public const DEFAULT_SUPPLIER_DAYS = 30;

    public const MAX_SUPPLIER_DAYS = 730;

    public const GROUP_CATEGORY = 'category';

    public const GROUP_BRANCH = 'branch';

    public const GROUP_SUPPLIER = 'supplier';

    /** @var array<string, string> */
    public const GROUP_LABELS = [
        self::GROUP_CATEGORY => 'Kategori',
        self::GROUP_BRANCH => 'Cawangan',
        self::GROUP_SUPPLIER => 'Supplier',
    ];

    public function __construct(protected BackOfficeActionsAdvisor $advisor) {}

    /** "DAMAI - saiz 20.5, 1.92g x3" - satu baris permintaan cawangan utk paparan/eksport. */
    public static function describeRequest(array $request): string
    {
        $parts = array_filter([
            $request['size'] ? "saiz {$request['size']}" : null,
            $request['weight'] ? "{$request['weight']}g" : null,
        ]);

        return $request['store_code'].($parts ? ' - '.implode(', ', $parts) : '').' x'.$request['qty'];
    }

    /**
     * Stok setiap cawangan + agihan Qty order (Restock sahaja): `short` = qty yg BO taip sendiri (beza drpd
     * cadangan) tak cukup menampung permintaan cawangan itu (sama dgn dot amber di halaman).
     *
     * @param  array<string, mixed>  $item
     * @return array<int, array{store_code: string, stock: int, alloc: int, after: int, requested: int, short: bool}>
     */
    public static function branchRows(array $item): array
    {
        $allocation = (array) ($item['allocation'] ?? []);
        $requested = collect($item['plan']['branches'] ?? [])->mapWithKeys(fn (array $b) => [$b['store_code'] => (int) $b['requested']]);
        $manual = (int) ($item['qty_to_order'] ?? 0) !== (int) ($item['suggested_qty'] ?? 0);

        return collect($item['stock'] ?? [])->map(function (array $s) use ($allocation, $requested, $manual) {
            $alloc = (int) ($allocation[$s['store_code']] ?? 0);
            $asked = (int) ($requested[$s['store_code']] ?? 0);

            return [
                'store_code' => $s['store_code'],
                'stock' => (int) $s['stock'],
                'alloc' => $alloc,
                'after' => (int) $s['stock'] + $alloc,
                'requested' => $asked,
                'short' => $manual && $alloc < $asked,
            ];
        })->all();
    }

    /**
     * Cawangan yg dapat bahagian sendiri pd kumpulan "Ikut Cawangan": cawangan yg REQUEST design ini ATAU yg
     * MENERIMA unit drpd agihan order (cawangan yg tak request tapi stok rendah pun nampak design ini).
     *
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    public static function branchKeys(array $item): array
    {
        $keys = collect($item['requests'] ?? [])->pluck('store_code')
            ->merge(collect((array) ($item['allocation'] ?? []))->filter(fn ($qty) => (int) $qty > 0)->keys())
            ->unique()
            ->values()
            ->all();

        return $keys ?: ['Tiada permintaan cawangan'];
    }

    /** "1 → 3 (+2)" atau "1" - stok cawangan bahagian ini (section_store) sebelum & selepas order. */
    public static function describeSectionStock(array $item): string
    {
        $row = collect(self::branchRows($item))->firstWhere('store_code', $item['section_store'] ?? null);

        if (! $row) {
            return '-';
        }

        return (string) $row['stock'].($row['alloc'] > 0 ? " → {$row['after']} (+{$row['alloc']})" : '');
    }

    /** "WM 0 → 3 (+3), DAMAI 1" - stok semasa, dan stok selepas order utk cawangan yg menerima unit. */
    public static function describeStock(array $item): string
    {
        return collect(self::branchRows($item))
            ->map(fn (array $r) => "{$r['store_code']} {$r['stock']}".($r['alloc'] > 0 ? " → {$r['after']} (+{$r['alloc']})" : ''))
            ->implode(', ');
    }

    /** "3" atau "3 (DAMAI 2, WM 5)" - paras lalai design + pengecualian cawangan. */
    public static function describeParas(array $item): string
    {
        $overrides = (array) ($item['branch_targets'] ?? []);
        ksort($overrides);

        $exceptions = collect($overrides)->map(fn ($value, $store) => "{$store} {$value}")->implode(', ');

        return (string) ((int) ($item['target_stock'] ?? 3)).($exceptions !== '' ? " ({$exceptions})" : '');
    }

    /** "DAMAI request 3, dapat 2" - cawangan yg request tapi agihan kurang (kosong jika tiada). */
    public static function describeShort(array $item): string
    {
        return collect(self::branchRows($item))
            ->filter(fn (array $r) => $r['short'])
            ->map(fn (array $r) => "{$r['store_code']} request {$r['requested']}, dapat {$r['alloc']}")
            ->implode('; ');
    }

    /**
     * Susun item mengikut kumpulan eksport: kategori, cawangan peminta (setiap cawangan nampak
     * permintaan DIA sahaja) atau supplier teratas.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{title: string, items: array<int, array<string, mixed>>}>
     */
    public function sections(array $items, string $group): array
    {
        $sections = [];

        foreach ($items as $item) {
            $keys = match ($group) {
                self::GROUP_BRANCH => self::branchKeys($item),
                self::GROUP_SUPPLIER => [($item['suppliers'][0]['vendor_code'] ?? null) ?: 'Tiada data supplier'],
                default => [($item['category_name'] ?? null) ?: 'Tanpa kategori'],
            };

            foreach ($keys as $key) {
                $entry = $group === self::GROUP_BRANCH
                    ? array_merge($item, [
                        'requests' => array_values(array_filter($item['requests'], fn (array $r) => $r['store_code'] === $key)),
                    ])
                    : $item;

                if ($group === self::GROUP_BRANCH) {
                    $entry['requested_total'] = (int) collect($entry['requests'])->sum('qty');
                    // Unit yg cawangan SECTION ini dapat drpd order (utk lajur "Cawangan ini dapat") & stok cawangan ini.
                    $entry['section_store'] = $key;
                    $entry['section_alloc'] = (int) (((array) ($item['allocation'] ?? []))[$key] ?? 0);
                }

                $sections[$key][] = $entry;
            }
        }

        ksort($sections);

        return collect($sections)->map(fn (array $rows, string $title) => ['title' => $title, 'items' => $rows])->values()->all();
    }

    /**
     * Jualan TERKINI setiap design: tarikh piece terakhir terjual (semua cawangan termasuk web) + cawangan + berapa hari
     * lepas. Design yg tak pernah terjual tiada dlm hasil. Satu query dgn join ke MAX(SalesDate) per kod (tanpa fungsi
     * tetingkap); seri (beberapa cawangan sama masa) -> cawangan pertama ikut abjad.
     *
     * `sold_30d` = unit terjual dlm LATEST_SALES_DAYS hari terakhir (setiap piece terjual = 1 unit).
     *
     * @param  array<int, string>  $codes
     * @return array<string, array{date: string, store: string, days_ago: int, sold_30d: int}>
     */
    public function latestSales(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $codes))));

        if ($codes === []) {
            return [];
        }

        $table = (new InventoryPiece)->getTable();

        $latest = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->whereNotNull('SalesDate')
            ->selectRaw('InternalCode, MAX(SalesDate) as last_sold_at, SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as sold_recent', [now()->subDays(self::LATEST_SALES_DAYS)->startOfDay()])
            ->groupBy('InternalCode')
            ->toBase();

        return InventoryPiece::query()
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('latest.InternalCode', '=', "{$table}.InternalCode")
                ->on('latest.last_sold_at', '=', "{$table}.SalesDate"))
            ->whereIn("{$table}.InternalCode", $codes)
            ->selectRaw("{$table}.InternalCode as code, MAX(latest.last_sold_at) as last_sold_at, MAX(latest.sold_recent) as sold_recent, MIN({$table}.StoreCode) as store")
            ->groupBy("{$table}.InternalCode")
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [trim((string) $r->code) => [
                'date' => Carbon::parse($r->last_sold_at)->toDateString(),
                'store' => trim((string) $r->store),
                'days_ago' => (int) Carbon::parse($r->last_sold_at)->startOfDay()->diffInDays(now()->startOfDay()),
                'sold_30d' => (int) $r->sold_recent,
            ]])
            ->all();
    }

    /** Tempoh jualan supplier yg sah (1..MAX_SUPPLIER_DAYS hari); nilai tak sah/kosong -> lalai. */
    public static function clampSupplierDays(mixed $value): int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT);

        if ($days === false) {
            return self::DEFAULT_SUPPLIER_DAYS;
        }

        return max(1, min(self::MAX_SUPPLIER_DAYS, $days));
    }

    /**
     * Supplier teratas setiap design dlm Senarai Restock + "Best Supplier to Meet" sahaja (tanpa stok/jualan
     * cawangan) - dimuat atas permintaan (butang Load supplier) kerana query ke cermin agak berat.
     *
     * Supplier ikut KOD design, jadi design yg BELUM disimpan (baru masuk Restock di halaman) pun boleh dimuat:
     * `$unsaved` = kod => qty order (utk jumlah unit di "Best Supplier to Meet"). `codes` dlm respons = senarai kod
     * yg dikira (utk bezakan "tiada supplier" drpd "belum dimuat").
     *
     * @param  array<string, int>  $unsaved  kod design belum disimpan => kuantiti order
     * @return array{days: int, codes: array<int, string>, items: object, suppliers: array<int, array<string, mixed>>}
     */
    public function supplierData(int $days = self::DEFAULT_SUPPLIER_DAYS, array $unsaved = []): array
    {
        $days = self::clampSupplierDays($days);

        $qtyByCode = RestockListItem::query()
            ->where('status', RestockListItem::STATUS_DRAFT)
            ->orderBy('internal_code')
            ->get(['internal_code', 'qty_to_order'])
            ->mapWithKeys(fn (RestockListItem $item) => [trim((string) $item->internal_code) => (int) $item->qty_to_order])
            ->all();

        foreach ($unsaved as $code => $qty) {
            $code = trim((string) $code);

            if ($code !== '' && ! array_key_exists($code, $qtyByCode)) {
                $qtyByCode[$code] = (int) $qty;
            }
        }

        if ($qtyByCode === []) {
            return ['days' => $days, 'codes' => [], 'items' => (object) [], 'suppliers' => []];
        }

        $codes = array_map('strval', array_keys($qtyByCode));
        $vendors = $this->topVendors($codes, $days);

        $rows = collect($qtyByCode)->map(fn (int $qty, $code) => [
            'internal_code' => (string) $code,
            'qty_to_order' => $qty,
            'suppliers' => $vendors[(string) $code] ?? [],
        ])->values();

        return ['days' => $days, 'codes' => $codes, 'items' => (object) $vendors, 'suppliers' => $this->bestSuppliers($rows)];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, suppliers: array<int, array<string, mixed>>}
     */
    public function build(bool $withSuppliers = false, int $supplierDays = self::DEFAULT_SUPPLIER_DAYS): array
    {
        $items = RestockListItem::query()
            ->where('status', RestockListItem::STATUS_DRAFT)
            ->orderBy('category_name')
            ->orderBy('internal_code')
            ->get();

        if ($items->isEmpty()) {
            return ['items' => [], 'suppliers' => []];
        }

        $codes = $items->pluck('internal_code')->map(fn ($c) => trim((string) $c))->all();

        $lines = BranchDemandRequestLine::query()
            ->with('request')
            ->whereIn('internal_code', $codes)
            ->where('fulfillment_status', BranchDemandRequestLine::FULFILLMENT_ORDER)
            ->whereNull('done_at')
            ->get()
            ->groupBy(fn (BranchDemandRequestLine $l) => trim((string) $l->internal_code));

        $stock = $this->advisor->stockByCode($codes);
        $sales = $this->advisor->salesByCode($codes);
        $branches = $this->advisor->branchCodes();
        $meta = $this->meta($codes);
        $referenceImages = ProductImageFetcher::referenceImages($codes);
        $latestSales = $this->latestSales($codes);
        $vendors = $withSuppliers ? $this->topVendors($codes, self::clampSupplierDays($supplierDays)) : [];

        $rows = $items->map(function (RestockListItem $item) use ($lines, $stock, $sales, $branches, $meta, $referenceImages, $vendors, $withSuppliers, $latestSales) {
            $code = trim((string) $item->internal_code);
            $codeLines = $lines->get($code, collect());
            $stores = $stock[$code] ?? [];
            $codeSales = $sales[$code] ?? [];
            $info = $meta[$code] ?? null;

            $requests = $codeLines
                ->map(fn (BranchDemandRequestLine $l) => [
                    'store_code' => trim((string) $l->request->store_code),
                    'request_number' => $l->request->request_number,
                    'size' => filled($l->size) ? trim((string) $l->size) : null,
                    'weight' => filled($l->weight) ? trim((string) $l->weight) : null,
                    'qty' => $this->advisor->outstanding($l),
                    'remark' => filled($l->remark) ? trim((string) $l->remark) : null,
                ])
                ->sortBy('store_code')
                ->values();

            $requested = $requests->groupBy('store_code')->map(fn (Collection $r) => (int) $r->sum('qty'))->all();

            $stockRows = collect($branches)
                ->merge(array_keys($stores))
                ->reject(fn (string $s) => $this->advisor->isExcludedStore($s))
                ->unique()->sort()->values()
                ->map(fn (string $s) => ['store_code' => $s, 'stock' => (int) ($stores[$s] ?? 0)]);

            $targetStock = (int) $item->target_stock;
            $branchTargets = (array) ($item->branch_targets ?? []);
            $plan = $this->advisor->restockPlan($stores, $branches, $requested, $targetStock, $branchTargets);

            return [
                'id' => $item->id,
                'internal_code' => $code,
                'description' => $item->item_desc ?: ($info['description'] ?? null),
                'nickname' => $info['nickname'] ?? null,
                'image_url' => $info['image_url'] ?? null,
                'image_reference' => ProductImageFetcher::isReferenceImage($referenceImages, $code, $info['image_url'] ?? null),
                'category_name' => $item->category_name ?: ($info['category_code'] ?? null),
                'qty_to_order' => (int) $item->qty_to_order,
                'suggested_qty' => (int) $item->suggested_qty,
                'target_stock' => $targetStock,
                'branch_targets' => (object) $branchTargets,
                'allocation' => $item->allocation ?? RestockAllocator::allocate($plan['branches'], (int) $item->qty_to_order),
                'plan' => $plan,
                'requests' => $requests->all(),
                'requested_total' => (int) $requests->sum('qty'),
                'stock' => $stockRows->all(),
                'total_stock' => (int) array_sum($stores),
                'last_sale' => $latestSales[$code] ?? null,
                'sold_7d' => (int) collect($codeSales)->sum('sold_7d'),
                'sold_30d' => (int) collect($codeSales)->sum('sold_30d'),
                'suppliers' => $withSuppliers ? ($vendors[$code] ?? []) : [],
            ];
        })->values();

        return [
            'items' => $rows->all(),
            'suppliers' => $withSuppliers ? $this->bestSuppliers($rows) : [],
        ];
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, array{description: ?string, nickname: ?string, image_url: ?string, category_code: ?string}>
     */
    protected function meta(array $codes): array
    {
        return InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, MAX(Description) as description, MAX(nickname) as nickname, MAX(image_url) as image_url, MAX(CategoryCode) as category_code')
            ->groupBy('InternalCode')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [trim((string) $r->InternalCode) => [
                'description' => filled($r->description) ? trim((string) $r->description) : null,
                'nickname' => filled($r->nickname) ? trim((string) $r->nickname) : null,
                'image_url' => filled($r->image_url) ? $r->image_url : null,
                'category_code' => filled($r->category_code) ? trim((string) $r->category_code) : null,
            ]])
            ->all();
    }

    /**
     * 3 supplier teratas setiap design ikut JUALAN dlm $days hari terakhir (piece terjual; `sold` = unit terjual
     * dlm tempoh itu). Kalau design tiada jualan langsung dlm tempoh itu, guna bilangan piece yg pernah dibeli
     * (sandaran; `sold` kekal 0).
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<int, array{vendor_code: string, sold: int, pieces: int}>>
     */
    protected function topVendors(array $codes, int $days = self::DEFAULT_SUPPLIER_DAYS): array
    {
        $since = now()->subDays($days)->startOfDay();

        $rows = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, VendorCode, SUM(CASE WHEN SalesDate >= ? THEN 1 ELSE 0 END) as sold, COUNT(*) as pieces', [$since])
            ->groupBy('InternalCode', 'VendorCode')
            ->toBase()
            ->get();

        return $rows
            ->groupBy(fn ($r) => trim((string) $r->InternalCode))
            ->map(function (Collection $group) {
                $hasSales = $group->sum('sold') > 0;

                return $group
                    ->sortByDesc(fn ($r) => $hasSales ? [(int) $r->sold, (int) $r->pieces] : [(int) $r->pieces, 0])
                    ->take(self::TOP_SUPPLIERS)
                    ->map(fn ($r) => [
                        'vendor_code' => trim((string) $r->VendorCode),
                        'sold' => (int) $r->sold,
                        'pieces' => (int) $r->pieces,
                    ])
                    ->values()
                    ->all();
            })
            ->all();
    }

    /**
     * "Best supplier to meet": supplier yg muncul di 3 teratas paling banyak design dlm senarai
     * (seri -> jualan lebih tinggi), dgn jumlah unit order design-design tsb.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{vendor_code: string, designs: int, sold: int, qty_to_order: int, codes: array<int, string>}>
     */
    protected function bestSuppliers(Collection $rows): array
    {
        $agg = [];

        foreach ($rows as $row) {
            foreach ($row['suppliers'] as $v) {
                $agg[$v['vendor_code']] ??= ['vendor_code' => $v['vendor_code'], 'designs' => 0, 'sold' => 0, 'qty_to_order' => 0, 'codes' => []];
                $agg[$v['vendor_code']]['designs']++;
                $agg[$v['vendor_code']]['sold'] += $v['sold'];
                $agg[$v['vendor_code']]['qty_to_order'] += $row['qty_to_order'];
                $agg[$v['vendor_code']]['codes'][] = $row['internal_code'];
            }
        }

        return collect($agg)
            ->sortBy([['designs', 'desc'], ['sold', 'desc']])
            ->values()
            ->all();
    }
}
