<?php

namespace App\Support;

use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cadangan restock silang Kategori x Cawangan, per Saiz ATAU per Berat - 100% drpd data
 * JEMiSys sebenar (TblInventory), TIADA pergantungan pada PO/GRN/data diisi manual.
 *
 * Guna formula velocity/target_stock SAMA spt OrderRecommendationCalculator (SalesVelocityHelper)
 * supaya konsisten merentas sistem, tapi pada peringkat Kategori+Cawangan+Saiz/Berat (bukan
 * VendorCode+InternalCode) - jawapan "apa perlu restock, kategori/cawangan/saiz mana".
 */
class RestockAnalysisCalculator
{
    public const TARGET_COVER_MONTHS = OrderRecommendationCalculator::TARGET_COVER_MONTHS;

    public const MIN_SAMPLE = 3;

    public const WEIGHT_BINS = [0, 1, 2, 3, 5, 10, 20, 50, PHP_INT_MAX];

    public const WEIGHT_LABELS = ['0-1g', '1-2g', '2-3g', '3-5g', '5-10g', '10-20g', '20-50g', '50g+'];

    public const VERDICT_SOLD_OUT = 'Perlu Restock (Sold Out)';

    public const VERDICT_RESTOCK = 'Perlu Restock';

    public const VERDICT_OK = 'Stok Cukup';

    public const VERDICT_OVERSTOCK = 'Overstock';

    public const VERDICT_NO_DATA = 'Data Tak Cukup';

    public static function bySize(): Collection
    {
        return collect(Cache::remember('restock_by_size', 3600, function () {
            return retry(6, fn () => static::computeBySize()->toArray(), 800);
        }));
    }

    public static function byWeight(): Collection
    {
        return collect(Cache::remember('restock_by_weight', 3600, function () {
            return retry(6, fn () => static::computeByWeight()->toArray(), 800);
        }));
    }

    protected static function computeBySize(): Collection
    {
        // JewelSize (TEXT, ~280 nilai unik - jauh lebih kecil drpd GoldWeight berterusan) -
        // kumpul RAW dulu dlm SQL (selamat, bukan combinatorial explosion spt berat), kemudian
        // normalize label (sizeLabel()) & gabung semula dlm PHP - sepadan pendekatan Python
        // analytics.py _size_label() (buang trailing ".0", "(tiada)" utk kosong).
        $raw = InventoryPiece::query()
            ->realVendor()
            ->selectRaw('CategoryCode, StoreCode, JewelSize, '.
                'COUNT(*) as pieces_received, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(QtyOnHand) as current_stock')
            ->groupBy('CategoryCode', 'StoreCode', 'JewelSize')
            ->get();

        $merged = $raw->groupBy(fn ($r) => $r->CategoryCode.'|'.$r->StoreCode.'|'.static::sizeLabel($r->JewelSize))
            ->map(function ($rows) {
                $first = $rows->first();

                return (object) [
                    'CategoryCode' => $first->CategoryCode,
                    'StoreCode' => $first->StoreCode,
                    'bucket' => static::sizeLabel($first->JewelSize),
                    'pieces_received' => $rows->sum('pieces_received'),
                    'pieces_sold' => $rows->sum('pieces_sold'),
                    'current_stock' => $rows->sum('current_stock'),
                ];
            })->values();

        return static::finalize($merged);
    }

    public static function sizeLabel(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '(tiada)';
        }
        $s = trim((string) $value);
        if (is_numeric($s)) {
            $f = (float) $s;

            return $f == (int) $f ? (string) (int) $f : (string) $f;
        }

        return $s;
    }

    protected static function computeByWeight(): Collection
    {
        // Bucket berat DALAM SQL (CASE WHEN) sebelum GROUP BY - elak kumpul ikut GoldWeight
        // mentah (float berterusan) yg cipta beribu kumpulan tak perlu (punca OOM sblm ni).
        $caseExpr = static::weightBucketSqlCase();

        $raw = InventoryPiece::query()
            ->realVendor()
            ->selectRaw("CategoryCode, StoreCode, {$caseExpr} as bucket, ".
                'COUNT(*) as pieces_received, '.
                'SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as pieces_sold, '.
                'SUM(QtyOnHand) as current_stock')
            ->groupBy('CategoryCode', 'StoreCode', 'bucket')
            ->get();

        return static::finalize($raw);
    }

    protected static function weightBucketSqlCase(): string
    {
        $cases = [];
        foreach (self::WEIGHT_LABELS as $i => $label) {
            $min = self::WEIGHT_BINS[$i];
            $max = self::WEIGHT_BINS[$i + 1];
            $upper = $max === PHP_INT_MAX ? '' : " AND GoldWeight < {$max}";
            $cases[] = "WHEN GoldWeight >= {$min}{$upper} THEN '{$label}'";
        }
        $whens = implode(' ', $cases);

        return "CASE WHEN GoldWeight IS NULL THEN '(tiada)' {$whens} ELSE '50g+' END";
    }

    /** Versi PHP (bukan SQL) bagi weightBucketSqlCase() - guna WEIGHT_BINS/LABELS sama, utk ujian/konsistensi. */
    public static function weightBucket(mixed $grams): string
    {
        if ($grams === null) {
            return '(tiada)';
        }
        $g = (float) $grams;
        foreach (self::WEIGHT_LABELS as $i => $label) {
            $max = self::WEIGHT_BINS[$i + 1];
            if ($g >= self::WEIGHT_BINS[$i] && ($max === PHP_INT_MAX || $g < $max)) {
                return $label;
            }
        }

        return '50g+';
    }

    protected static function finalize(Collection $raw): Collection
    {
        $salesWindowDays = SalesVelocityHelper::salesWindowDays();
        $categoryNames = \App\Models\Jemisys\Category::pluck('Description', 'CategoryCode');

        $out = $raw->map(function ($r) use ($salesWindowDays, $categoryNames) {
            $piecesReceived = (int) $r->pieces_received;
            $piecesSold = (int) $r->pieces_sold;
            $currentStock = (int) $r->current_stock;

            $velocity = SalesVelocityHelper::velocity($piecesSold, $salesWindowDays);
            $targetStock = SalesVelocityHelper::targetStock($velocity, self::TARGET_COVER_MONTHS);

            if ($piecesReceived < self::MIN_SAMPLE) {
                $verdict = self::VERDICT_NO_DATA;
            } elseif ($currentStock === 0 && $velocity > 0) {
                $verdict = self::VERDICT_SOLD_OUT;
            } elseif ($currentStock < $targetStock) {
                $verdict = self::VERDICT_RESTOCK;
            } elseif ($targetStock > 0 && $currentStock > $targetStock * 2) {
                $verdict = self::VERDICT_OVERSTOCK;
            } else {
                $verdict = self::VERDICT_OK;
            }

            return [
                'category_code' => $r->CategoryCode,
                'category_name' => $categoryNames[$r->CategoryCode] ?? $r->CategoryCode,
                'store_code' => $r->StoreCode,
                'bucket' => $r->bucket,
                'pieces_received' => $piecesReceived,
                'pieces_sold' => $piecesSold,
                'current_stock' => $currentStock,
                'velocity_per_month' => $velocity,
                'target_stock' => $targetStock,
                'gap' => $targetStock - $currentStock,
                'verdict' => $verdict,
            ];
        });

        return $out->sortByDesc('gap')->values();
    }
}
