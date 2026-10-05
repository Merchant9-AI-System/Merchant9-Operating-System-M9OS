<?php

namespace App\Support;

use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\InventoryPiece;
use App\Models\RestockListItem;
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
                self::GROUP_BRANCH => collect($item['requests'])->pluck('store_code')->unique()->all() ?: ['Tiada permintaan cawangan'],
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
                }

                $sections[$key][] = $entry;
            }
        }

        ksort($sections);

        return collect($sections)->map(fn (array $rows, string $title) => ['title' => $title, 'items' => $rows])->values()->all();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, suppliers: array<int, array<string, mixed>>}
     */
    public function build(bool $withSuppliers = false): array
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
        $vendors = $withSuppliers ? $this->topVendors($codes) : [];

        $rows = $items->map(function (RestockListItem $item) use ($lines, $stock, $sales, $branches, $meta, $vendors, $withSuppliers) {
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

            return [
                'id' => $item->id,
                'internal_code' => $code,
                'description' => $item->item_desc ?: ($info['description'] ?? null),
                'nickname' => $info['nickname'] ?? null,
                'image_url' => $info['image_url'] ?? null,
                'category_name' => $item->category_name ?: ($info['category_code'] ?? null),
                'qty_to_order' => (int) $item->qty_to_order,
                'suggested_qty' => (int) $item->suggested_qty,
                'plan' => $this->advisor->restockPlan($stores, $branches, $requested),
                'requests' => $requests->all(),
                'requested_total' => (int) $requests->sum('qty'),
                'stock' => $stockRows->all(),
                'total_stock' => (int) array_sum($stores),
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
     * 3 supplier teratas setiap design ikut JUALAN KESELURUHAN (piece terjual, semua tarikh).
     * Kalau design belum pernah terjual, guna bilangan piece yg pernah dibeli (sandaran).
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<int, array{vendor_code: string, sold: int, pieces: int}>>
     */
    protected function topVendors(array $codes): array
    {
        $rows = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, VendorCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, COUNT(*) as pieces')
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
