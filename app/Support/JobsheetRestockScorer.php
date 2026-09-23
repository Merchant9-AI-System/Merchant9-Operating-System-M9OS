<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Collection;

/**
 * Cadangan tindakan per DESIGN (InternalCode)/CAWANGAN yg terpapar pd carian Jobsheet Lookup -
 * rujuk JobsheetLookupController & JobsheetLookup/Index.vue lajur "Cadangan". DUA peraturan
 * SAHAJA (keputusan pengguna, gantikan skor kumulatif 0-100 & 6 isyarat asal - terlalu kabur,
 * byk keping fizikal design SAMA ulang badge IDENTIK, staf tak faham tindakan sebenar):
 *
 * 1. RESTOCK (design-level, keutamaan TERTINGGI) - antara SEMUA cawangan yg MASIH ADA stok
 *    design ni (balance > 0), kalau ADA ≥2 cawangan macam tu DAN SETIAP SATU balance = 1
 *    (xde satu pun ada lebihan) - stok serata tapi nipis, pindah antara cawangan x membantu
 *    (jumlah keseluruhan tetap sama), jadi cadang RESTOCK SAHAJA drpd vendor, bukan rearrange.
 *
 * 2. REARRANGE (per-cawangan/baris, cuma disemak kalau syarat #1 TAK kena) - cawangan pd baris
 *    ni SENDIRI ada balance 0 ATAU 1, DAN cawangan ni ada rekod JUALAN design ni dlm tempoh
 *    trend (RestockAnalysisCalculator::DEFAULT_PERIOD, sama dgn seluruh sistem) - cawangan ni
 *    terbukti laku tapi nipis/dah habis, cadang PINDAH stok MASUK drpd cawangan lain yg ada
 *    lebihan (balance ≥2). Kalau xde langsung cawangan sumber (semua cawangan lain pun nipis/xde
 *    stok), xde apa nak dipindah - tiada tindakan dicadang (elak cadang "rearrange" yg mustahil).
 *
 * `target_branches` bawa maksud BERBEZA ikut action: utk RESTOCK, cawangan PALING LAKU design
 * ni (susun ikut jualan tempoh trend) - jawab "bila stok baharu sampai, agih ke mana dulu". Utk
 * REARRANGE, cawangan yg ADA LEBIHAN (balance ≥2, bukan cawangan ni sendiri) - jawab "pindah
 * drpd mana". StoreCode digunakan TERUS (bukan Store::Description - generik utk hampir semua
 * cawangan, tak berguna sbg nama).
 */
class JobsheetRestockScorer
{
    public const ACTION_RESTOCK = 'restock';

    public const ACTION_REARRANGE = 'rearrange';

    /** Balance <= ni dianggap "nipis" - calon restock (kalau serata) / rearrange (kalau laku). */
    public const THIN_BALANCE_MAX = 1;

    /** Bilangan cawangan sumber/sasaran maksimum dibawa dlm `target_branches`. */
    public const TARGET_BRANCH_LIMIT = 3;

    /**
     * @param  Collection<int, array{internal_code: ?string, store_code: ?string}>  $rows  baris carian Jobsheet Lookup (internal_code + store_code SETIAP baris)
     * @return array<string, array{action: ?string, action_label: ?string, action_color: string, action_detail: ?string, target_branches: array<int, string>}> keyed by "internal_code|store_code" - action boleh beza ikut baris (rujuk REARRANGE, khusus cawangan tsb)
     */
    public static function scoreRows(Collection $rows): array
    {
        $codes = $rows->pluck('internal_code')->filter()->map(fn ($c) => trim($c))->unique()->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $trendStart = RestockAnalysisCalculator::trendStartForPeriod(RestockAnalysisCalculator::DEFAULT_PERIOD);

        // Stok SEMASA setiap design PER CAWANGAN (cawangan yg MASIH ADA stok sahaja, >0) - asas
        // utk kedua-dua peraturan (semak "semua cawangan =1" & cari cawangan sumber rearrange).
        $stockByDesign = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, StoreCode, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode', 'StoreCode')
            ->havingRaw('SUM(QtyOnHand) > 0')
            ->toBase()
            ->get()
            ->groupBy(fn ($r) => trim((string) $r->InternalCode));

        // Jualan setiap design PER CAWANGAN dlm tempoh trend - utk syarat rearrange "ada jualan
        // dlm tempoh tertentu" & utk susun `target_branches` RESTOCK (cawangan paling laku).
        $soldByDesign = InventoryPiece::query()
            ->realVendor()
            ->whereIn('InternalCode', $codes)
            ->whereNotNull('SalesDate')
            ->where('SalesDate', '>=', $trendStart)
            ->selectRaw('InternalCode, StoreCode, COUNT(*) as sold')
            ->groupBy('InternalCode', 'StoreCode')
            ->toBase()
            ->get()
            ->groupBy(fn ($r) => trim((string) $r->InternalCode));

        $result = [];

        foreach ($rows as $row) {
            $internalCode = filled($row['internal_code'] ?? null) ? trim($row['internal_code']) : null;
            $storeCode = filled($row['store_code'] ?? null) ? trim($row['store_code']) : null;
            $key = "{$internalCode}|{$storeCode}";

            if (! $internalCode) {
                $result[$key] = self::noAction();

                continue;
            }

            $branchStocks = $stockByDesign->get($internalCode, collect());
            $branchSales = $soldByDesign->get($internalCode, collect())->keyBy(fn ($r) => trim((string) $r->StoreCode));

            $stocksOnly = $branchStocks->pluck('stock')->map(fn ($s) => (int) $s);

            if ($stocksOnly->count() >= 2 && $stocksOnly->max() <= self::THIN_BALANCE_MAX) {
                $topSellingBranches = $branchSales->sortByDesc('sold')
                    ->take(self::TARGET_BRANCH_LIMIT)
                    ->map(fn ($r) => trim((string) $r->StoreCode))
                    ->values()
                    ->all();

                $result[$key] = [
                    'action' => self::ACTION_RESTOCK,
                    'action_label' => 'Restock',
                    'action_color' => 'danger',
                    'action_detail' => "Semua {$stocksOnly->count()} cawangan yg ada design ni cuma 1 unit setiap satu - order lebih drpd vendor.",
                    'target_branches' => $topSellingBranches,
                ];

                continue;
            }

            $thisStock = (int) ($branchStocks->first(fn ($r) => trim((string) $r->StoreCode) === $storeCode)->stock ?? 0);
            $thisSold = (int) ($branchSales->get($storeCode)->sold ?? 0);

            if ($storeCode && $thisStock <= self::THIN_BALANCE_MAX && $thisSold > 0) {
                $sourceBranches = $branchStocks
                    ->filter(fn ($r) => trim((string) $r->StoreCode) !== $storeCode && (int) $r->stock >= 2)
                    ->sortByDesc('stock')
                    ->map(fn ($r) => trim((string) $r->StoreCode))
                    ->take(self::TARGET_BRANCH_LIMIT)
                    ->values();

                if ($sourceBranches->isNotEmpty()) {
                    $result[$key] = [
                        'action' => self::ACTION_REARRANGE,
                        'action_label' => 'Rearrange',
                        'action_color' => 'warning',
                        'action_detail' => "Cawangan {$storeCode} laku (ada jualan) tapi stok nipis/habis - pindah dari ".$sourceBranches->implode(', ')." ke {$storeCode}.",
                        'target_branches' => $sourceBranches->all(),
                    ];

                    continue;
                }
            }

            $result[$key] = self::noAction();
        }

        return $result;
    }

    /** @return array{action: null, action_label: null, action_color: string, action_detail: null, target_branches: array<int, string>} */
    protected static function noAction(): array
    {
        return [
            'action' => null,
            'action_label' => null,
            'action_color' => 'gray',
            'action_detail' => null,
            'target_branches' => [],
        ];
    }
}
