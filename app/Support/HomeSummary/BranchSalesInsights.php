<?php

namespace App\Support\HomeSummary;

use App\Enums\JemisysInventoryStatus;
use App\Models\InventoryMirror;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Support\OrderRecommendationCalculator;
use App\Support\SalesVelocityHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Jualan dan stok cawangan utk halaman /home ketua cawangan: SATU paparan gabungan "Terlaris" dan "Perlu
 * restock" - dikira drpd cermin JEMiSys tempatan (jemisys_inventory_mirror), BUKAN SQL Server live. Satu hasil
 * (overview) supaya angka jualan/stok/cadangan setiap design sentiasa sama di kedua-dua senarai.
 *
 * Peraturan data (disahkan pd cermin):
 * - Jualan cawangan = keping dgn SalesDate dlm tempoh & StoreCode = cawangan itu (sama pendekatan
 *   BranchActivityCalculator). Kumpul ikut design (InternalCode), bukan saiz.
 * - Stok cawangan = QtyOnHand tolak keping berstatus Transit ('5') / Loan Transit ('7') - keping itu turut
 *   QtyOnHand = 1 dlm cermin di cawangan asal tetapi sudah keluar secara fizikal.
 * - Barang Transit yg menuju cawangan ini (TransferToStore) dipaparkan berasingan ("incoming") dan ditolak
 *   drpd keperluan restock - elak cadangan utk barang yg sudah dalam perjalanan.
 * - Kategori bar (config dashboard.home_summary.excluded_categories) dikecualikan.
 *
 * Query tanpa index sesuai (RTRIM) ambil 3-4 saat - hasil dicache 1 jam; cermin disegerak -> Cache::flush().
 */
class BranchSalesInsights
{
    public const LIMIT = 20;

    public const DEFAULT_PERIOD = '1m';

    /** Hanya design dgn jualan sekurang-kurangnya ini dlm tempoh dianggap "laris" utk cadangan restock. */
    public const MIN_SOLD_FOR_RESTOCK = 2;

    /** Calon (ikut jualan tertinggi) yg dinilai sebelum diperingkat ikut keperluan restock. */
    public const CANDIDATE_LIMIT = 200;

    /** Stok cawangan <= ini dilabel "rendah" (amber) - 0 = habis (merah). */
    public const LOW_STOCK_MAX = 2;

    private const CACHE_MINUTES = 60;

    /** @return array<string, array{label: string, days: int}> */
    public static function periods(): array
    {
        return [
            '1w' => ['label' => '1 Minggu', 'days' => 7],
            '1m' => ['label' => '1 Bulan', 'days' => 30],
            '3m' => ['label' => '3 Bulan', 'days' => 90],
        ];
    }

    public static function normalizePeriod(?string $period): string
    {
        return array_key_exists((string) $period, self::periods()) ? (string) $period : self::DEFAULT_PERIOD;
    }

    /**
     * Dua senarai drpd SATU pengiraan (satu set query, satu cache):
     *  - top:     20 design terlaris (keping, gram, RM) - setiap baris turut bawa stok dan cadangan restock;
     *  - restock: 20 design yg laris (>= MIN_SOLD_FOR_RESTOCK) tetapi stok (termasuk transit ke sini) di bawah
     *             sasaran (kelajuan jualan x TARGET_COVER_MONTHS, formula sama OrderRecommendationCalculator),
     *             diperingkat ikut keperluan - dgn sumber stok: HQ, cawangan lain atau perlu order baharu.
     * Baris `suggest` = true bila design itu patut direstock.
     *
     * @return array{period: string, period_label: string, since: string, synced_at: string|null, top: array<int, array<string, mixed>>, restock: array<int, array<string, mixed>>, restock_total: int}
     */
    public function overview(string $store, string $period): array
    {
        $store = strtoupper(trim($store));
        $period = self::normalizePeriod($period);

        return Cache::remember("home_branch_overview:{$store}:{$period}", now()->addMinutes(self::CACHE_MINUTES), function () use ($store, $period) {
            $since = self::sinceFor($period);
            $days = self::periods()[$period]['days'];

            $candidates = $this->soldQuery($store, $since)
                ->selectRaw('RTRIM(InternalCode) AS code, COUNT(*) AS pieces, COALESCE(SUM(GoldWeight), 0) AS grams, COALESCE(SUM(SalesAmount), 0) AS rm, '.
                    'MAX(Description) AS description, RTRIM(MAX(CategoryCode)) AS category, MAX(nickname) AS nickname, MAX(image_url) AS image_url')
                ->groupByRaw('RTRIM(InternalCode)')
                ->orderByDesc('pieces')
                ->orderByDesc('rm')
                ->limit(self::CANDIDATE_LIMIT)
                ->toBase()
                ->get();

            $details = $this->detailsFor($candidates->pluck('code')->all(), $store);
            $categoryNames = $this->categoryNames();

            $rows = $candidates->map(function ($r) use ($details, $days, $categoryNames) {
                $d = $details[$r->code] ?? [];
                $pieces = (int) $r->pieces;
                $stock = (int) ($d['stock_here'] ?? 0);
                $incoming = (int) ($d['incoming'] ?? 0);
                $hq = (int) ($d['stock_hq'] ?? 0);
                $other = (int) ($d['stock_other'] ?? 0);
                $target = SalesVelocityHelper::targetStock(
                    SalesVelocityHelper::velocity($pieces, $days),
                    OrderRecommendationCalculator::TARGET_COVER_MONTHS,
                );
                $need = $target - $stock - $incoming;

                return [
                    'code' => (string) $r->code,
                    'name' => self::displayName($r->nickname, $r->description, (string) $r->code),
                    'description' => filled($r->description) ? trim((string) $r->description) : null,
                    'category' => $categoryNames[(string) $r->category] ?? ((string) $r->category ?: null),
                    'image_url' => filled($r->image_url) ? (string) $r->image_url : null,
                    'pieces' => $pieces,
                    'grams' => round((float) $r->grams, 2),
                    'rm' => round((float) $r->rm, 2),
                    'stock_here' => $stock,
                    'incoming' => $incoming,
                    'stock_tone' => self::stockTone($stock),
                    'target' => $target,
                    'need' => $need,
                    'suggest' => $pieces >= self::MIN_SOLD_FOR_RESTOCK && $need > 0,
                    'stock_hq' => $hq,
                    'stock_other' => $other,
                    'source' => self::sourceFor($hq, $other),
                ];
            });

            $restock = $rows->filter(fn (array $r) => $r['suggest'])
                ->sortBy([['need', 'desc'], ['pieces', 'desc']])
                ->values();

            return [
                'period' => $period,
                'period_label' => self::periods()[$period]['label'],
                'since' => $since->toDateString(),
                'synced_at' => $this->syncedAt(),
                'top' => $rows->take(self::LIMIT)->values()->all(),
                'restock' => $restock->take(self::LIMIT)->values()->all(),
                'restock_total' => $restock->count(),
            ];
        });
    }

    /** 'bad' = habis, 'warn' = rendah, 'good' = cukup. */
    public static function stockTone(int $stock): string
    {
        return match (true) {
            $stock <= 0 => 'bad',
            $stock <= self::LOW_STOCK_MAX => 'warn',
            default => 'good',
        };
    }

    /** 'hq' kalau ada di HQ, 'branches' kalau hanya cawangan lain, 'order' kalau tiada di mana-mana. */
    public static function sourceFor(int $hq, int $otherBranches): string
    {
        return match (true) {
            $hq > 0 => 'hq',
            $otherBranches > 0 => 'branches',
            default => 'order',
        };
    }

    private static function sinceFor(string $period): Carbon
    {
        return now()->subDays(self::periods()[$period]['days'])->startOfDay();
    }

    /** Asas: design sah (bukan kategori bar, vendor sah) yg terjual di cawangan ini sejak $since. */
    private function soldQuery(string $store, Carbon $since): Builder
    {
        return $this->baseQuery()
            ->whereRaw('UPPER(RTRIM(StoreCode)) = ?', [$store])
            ->whereNotNull('SalesDate')
            ->where('SalesDate', '>=', $since);
    }

    private function baseQuery(): Builder
    {
        $query = InventoryPiece::query()->realVendor()->whereRaw("RTRIM(InternalCode) <> ''");

        $excluded = (array) config('dashboard.home_summary.excluded_categories', []);

        if ($excluded !== []) {
            $query->whereRaw('RTRIM(CategoryCode) NOT IN ('.implode(',', array_fill(0, count($excluded), '?')).')', $excluded);
        }

        return $query;
    }

    /**
     * Stok (cawangan ini, HQ, cawangan lain) dan barang transit ke cawangan ini bagi satu set design.
     *
     * Dibina utk TIDAK membaca baris lebar: 200 design memadankan ~64,000 baris sejarah (ratusan keping setiap
     * design) dan query berkumpul biasa ambil ~10 saat. Jadual cermin ada indeks meliputi utk stok (InternalCode,
     * StoreCode, VendorCode, QtyOnHand) - query di bawah hanya menyentuh indeks itu. Nickname/imej TIDAK diambil di
     * sini (lajur TEXT, tiada indeks meliputi: ~13 saat) - ia diambil bersama keping terjual (sama bagi semua keping
     * satu design; liputan sama tepat, disahkan 185/200 imej).
     * Stok = jumlah QtyOnHand tolak keping berstatus Transit/Loan Transit (keping itu turut QtyOnHand = 1 di
     * cawangan asal tetapi sudah keluar secara fizikal); bilangannya diambil melalui indeks Status (sedikit baris).
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<string, mixed>>
     */
    private function detailsFor(array $codes, string $store): array
    {
        if ($codes === []) {
            return [];
        }

        $stockRows = InventoryPiece::query()->realVendor()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('RTRIM(InternalCode) AS code, UPPER(RTRIM(StoreCode)) AS store, SUM(QtyOnHand) AS qty')
            ->groupByRaw('RTRIM(InternalCode), UPPER(RTRIM(StoreCode))')
            ->toBase()
            ->get();

        $inFlightStatuses = array_map(fn (JemisysInventoryStatus $s) => $s->value, [
            JemisysInventoryStatus::Transit,
            JemisysInventoryStatus::LoanTransit,
            JemisysInventoryStatus::LoanReturnTransit,
        ]);

        $inFlightRows = InventoryPiece::query()->realVendor()
            ->whereIn('Status', $inFlightStatuses)
            ->whereIn('InternalCode', $codes)
            ->selectRaw('RTRIM(InternalCode) AS code, UPPER(RTRIM(StoreCode)) AS store, RTRIM(Status) AS status, UPPER(RTRIM(TransferToStore)) AS to_store, COUNT(*) AS n')
            ->groupByRaw('RTRIM(InternalCode), UPPER(RTRIM(StoreCode)), RTRIM(Status), UPPER(RTRIM(TransferToStore))')
            ->toBase()
            ->get();

        $transit = JemisysInventoryStatus::Transit->value;
        $result = [];

        foreach ($codes as $code) {
            $result[$code] = ['stock_here' => 0, 'stock_hq' => 0, 'stock_other' => 0, 'incoming' => 0];
        }

        $bucket = fn (string $storeCode): string => match (true) {
            $storeCode === $store => 'stock_here',
            $storeCode === 'HQ' => 'stock_hq',
            $storeCode === 'WEB' => 'ignore',
            default => 'stock_other',
        };

        foreach ($stockRows as $r) {
            $key = $bucket((string) $r->store);

            if ($key !== 'ignore' && isset($result[$r->code])) {
                $result[$r->code][$key] += (int) $r->qty;
            }
        }

        foreach ($inFlightRows as $r) {
            if (! isset($result[$r->code])) {
                continue;
            }

            $key = $bucket((string) $r->store);

            if ($key !== 'ignore') {
                $result[$r->code][$key] -= (int) $r->n;
            }

            if ((string) $r->status === $transit && (string) $r->to_store === $store) {
                $result[$r->code]['incoming'] += (int) $r->n;
            }
        }

        foreach ($result as &$row) {
            foreach (['stock_here', 'stock_hq', 'stock_other'] as $key) {
                $row[$key] = max(0, $row[$key]);
            }
        }
        unset($row);

        return $result;
    }

    /** @return array<string, string> kod kategori (trim) => nama */
    private function categoryNames(): array
    {
        return Category::query()->get(['CategoryCode', 'Description'])
            ->mapWithKeys(fn ($c) => [trim((string) $c->CategoryCode) => trim((string) $c->Description)])
            ->all();
    }

    private static function displayName(?string $nickname, ?string $description, string $code): string
    {
        return match (true) {
            filled($nickname) => trim($nickname),
            filled($description) => trim($description),
            default => $code,
        };
    }

    private function syncedAt(): ?string
    {
        $latest = InventoryMirror::query()->max('synced_at');

        return $latest ? Carbon::parse($latest)->format('Y-m-d H:i') : null;
    }
}
