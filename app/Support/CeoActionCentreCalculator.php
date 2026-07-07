<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * CEO Dashboard Phase 1 (A) - Action Centre. Gabung isyarat drpd kalkulator/query sedia ada
 * (RearrangeCalculator, SupplierPerformanceCalculator) + beberapa query baru (imbalance
 * cawangan, ideal weight hilang) jadi satu senarai amaran keutamaan.
 *
 * SEMUA alert di sini "rule-based suggestion" - threshold (cth. 2x average, 15%, 30%) ialah
 * andaian konservatif permulaan, BUKAN dikalibrasi drpd data sejarah sebenar (tiada snapshot
 * sejarah tersedia lagi - rujuk CapitalAgingCalculator). Sesuaikan threshold bila ada lebih
 * banyak data pemerhatian.
 */
class CeoActionCentreCalculator
{
    public const HIGH = 'High';

    public const MEDIUM = 'Medium';

    public const LOW = 'Low';

    /**
     * @return Collection<int, array{priority: string, issue: string, suggested_action: string, estimated_impact: string, data_source: string, rule_based: bool}>
     */
    public static function alerts(): Collection
    {
        $plain = Cache::remember('ceo_action_centre_alerts', 3600, function () {
            return retry(6, fn () => static::compute()->toArray(), 800);
        });

        return collect($plain);
    }

    protected static function compute(): Collection
    {
        $alerts = collect([
            static::deadStockAlert(),
            static::stockoutBestSellerAlert(),
            static::rearrangeAlert(),
            ...static::branchImbalanceAlerts(),
            static::missingIdealWeightAlert(),
            static::negativeMarginSupplierAlert(),
        ])->filter()->values();

        $order = [self::HIGH => 0, self::MEDIUM => 1, self::LOW => 2];

        return $alerts->sortBy(fn ($a) => $order[$a['priority']] ?? 3)->values();
    }

    protected static function deadStockAlert(): ?array
    {
        $today = now();
        $q = InventoryPiece::query()->onHand()->realVendor()
            ->where('PurchDate', '<=', $today->copy()->subDays(365));

        $count = (clone $q)->count();
        $value = (float) (clone $q)->sum('TotalCost');

        if ($count === 0) {
            return null;
        }

        return [
            'priority' => $value > 100_000 ? self::HIGH : self::MEDIUM,
            'issue' => "{$count} keping stok >12 bulan (dead stock), nilai kos RM ".number_format($value, 0),
            'suggested_action' => 'Semak untuk promosi/lelong/tukar design - modal terikat lama tak produktif.',
            'estimated_impact' => 'RM '.number_format($value, 0).' modal terikat',
            'data_source' => 'TblInventory (PurchDate, TotalCost, QtyOnHand)',
            'rule_based' => true,
        ];
    }

    protected static function stockoutBestSellerAlert(): ?array
    {
        $count = InventoryPiece::realVendor()
            ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode')
            // havingRaw kena ulang expression penuh, bukan alias - SQL Server tak benarkan alias dlm HAVING.
            ->havingRaw('SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) >= 3 AND SUM(QtyOnHand) = 0')
            ->get()
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'priority' => $count >= 10 ? self::HIGH : self::MEDIUM,
            'issue' => "{$count} design best-seller (pernah jual >=3) kini stok=0 di semua cawangan",
            'suggested_action' => 'Reorder segera drpd supplier - lihat senarai penuh di page Reorder Segera.',
            'estimated_impact' => 'Data tidak mencukupi utk anggaran RM (rujuk Best Seller Lost Opportunity)',
            'data_source' => 'TblInventory (SalesDate, QtyOnHand)',
            'rule_based' => true,
        ];
    }

    protected static function rearrangeAlert(): ?array
    {
        $count = RearrangeCalculator::recommendations()->count();

        if ($count === 0) {
            return null;
        }

        return [
            'priority' => $count >= 15 ? self::HIGH : self::MEDIUM,
            'issue' => "{$count} design ada peluang rearrange antara cawangan (donor lebih, receiver sold out)",
            'suggested_action' => 'Semak & cipta transfer di page Rearrange.',
            'estimated_impact' => 'Data tidak mencukupi utk anggaran RM',
            'data_source' => 'RearrangeCalculator (TblInventory per StoreCode)',
            'rule_based' => true,
        ];
    }

    /** @return array<int, array|null> */
    protected static function branchImbalanceAlerts(): array
    {
        $rows = InventoryPiece::query()->onHand()->realVendor()->physicalStore()
            ->selectRaw('StoreCode, SUM(GoldWeight) as gold_weight')
            ->groupBy('StoreCode')
            ->get();

        if ($rows->count() < 2) {
            return [null, null];
        }

        $avg = (float) $rows->avg('gold_weight');
        if ($avg <= 0) {
            return [null, null];
        }

        $high = $rows->sortByDesc('gold_weight')->first();
        $low = $rows->sortBy('gold_weight')->first();

        $highAlert = null;
        if ((float) $high->gold_weight > $avg * 2) {
            $highAlert = [
                'priority' => self::MEDIUM,
                'issue' => "Cawangan {$high->StoreCode} pegang emas jauh lebih tinggi drpd purata cawangan lain (".
                    number_format($high->gold_weight / 1000, 1).'kg vs purata '.number_format($avg / 1000, 1).'kg)',
                'suggested_action' => 'Semak sama ada perlu rearrange sebahagian stok ke cawangan lain.',
                'estimated_impact' => 'Data tidak mencukupi utk anggaran RM',
                'data_source' => 'TblInventory (GoldWeight, StoreCode)',
                'rule_based' => true,
            ];
        }

        $lowAlert = null;
        if ($low->StoreCode !== $high->StoreCode && (float) $low->gold_weight < $avg * 0.3) {
            $lowAlert = [
                'priority' => self::LOW,
                'issue' => "Cawangan {$low->StoreCode} pegang emas jauh lebih rendah drpd purata cawangan lain (".
                    number_format($low->gold_weight / 1000, 1).'kg vs purata '.number_format($avg / 1000, 1).'kg)',
                'suggested_action' => 'Semak sama ada cawangan ni perlu restock drpd cawangan lain/supplier.',
                'estimated_impact' => 'Data tidak mencukupi utk anggaran RM',
                'data_source' => 'TblInventory (GoldWeight, StoreCode)',
                'rule_based' => true,
            ];
        }

        return [$highAlert, $lowAlert];
    }

    protected static function missingIdealWeightAlert(): ?array
    {
        $count = Store::where(fn ($q) => $q->whereNull('IdealGoldWeight916')->orWhere('IdealGoldWeight916', 0))->count();
        $total = Store::count();

        if ($count === 0 || $total === 0) {
            return null;
        }

        return [
            'priority' => self::LOW,
            'issue' => "{$count} drpd {$total} cawangan belum ada sasaran ideal gold weight (TblStore.IdealGoldWeight916)",
            'suggested_action' => 'Minta pengurusan tetapkan sasaran ideal setiap cawangan utk aktifkan perbandingan stok vs ideal.',
            'estimated_impact' => 'Tiada (isu data, bukan kewangan)',
            'data_source' => 'TblStore (IdealGoldWeight916)',
            'rule_based' => true,
        ];
    }

    protected static function negativeMarginSupplierAlert(): ?array
    {
        $negative = SupplierPerformanceCalculator::performance()
            ->filter(fn ($r) => $r['margin_pct'] !== null && $r['margin_pct'] < 0);

        if ($negative->isEmpty()) {
            return null;
        }

        $worst = $negative->sortBy('margin_pct')->first();

        return [
            'priority' => $negative->count() >= 3 ? self::HIGH : self::MEDIUM,
            'issue' => "{$negative->count()} supplier ada margin negatif (terburuk: {$worst['vendor_name']} {$worst['margin_pct']}%)",
            'suggested_action' => 'Semak harga beli/jual dgn supplier terlibat - lihat page Prestasi Supplier.',
            'estimated_impact' => 'Margin terburuk: '.$worst['margin_pct'].'% (sampel '.$worst['margin_sample_size'].' jualan)',
            'data_source' => 'SupplierPerformanceCalculator (TblInventory SalesAmount, ~61% liputan)',
            'rule_based' => true,
        ];
    }
}
