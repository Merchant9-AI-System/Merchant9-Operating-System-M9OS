<?php

namespace App\Support;

use App\Models\DailyAssetPosition;
use App\Models\Jemisys\InventoryPiece;
use App\Models\PhysicalGoldReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ringkasan & reconciliation utk modul "Daily Company Asset Position" (data dikeyin accountant,
 * jadual sendiri - BUKAN JEMiSys). Reconciliation di sini SEKADAR baca & banding (read-only) -
 * TIADA pelarasan automatik inventori JEMiSys, TIADA post accounting entry (rujuk arahan asal).
 *
 * NOTA JUJUR: JEMiSys tiada konsep snapshot harian (cuma "stok skrg"), jadi mapping di bawah
 * ialah proksi terbaik yg boleh (usaha munasabah), bukan padanan tepat 100%:
 * - "Sales weight" JEMiSys = SUM(GoldWeight) WHERE SalesDate = tarikh berkenaan
 * - "New stock" JEMiSys    = SUM(GoldWeight) WHERE PurchDate = tarikh berkenaan
 * - "Closing stock" JEMiSys = jumlah stok ON HAND SEKARANG (bukan snapshot pd tarikh tsb) -
 *   hanya bermakna bila dibanding dgn rekod accountant TERKINI, bukan tarikh lampau.
 */
class DailyAssetPositionCalculator
{
    public const STATUS_GREEN = 'green';

    public const STATUS_YELLOW = 'yellow';

    public const STATUS_RED = 'red';

    /** Kelas ketulenan JEMiSys utk harga emas sebenar (emas tulen 999). */
    public const MARKET_PRICE_CLASS = '999';

    public static function latestEntry(): ?DailyAssetPosition
    {
        return DailyAssetPosition::query()->orderByDesc('entry_date')->first();
    }

    /** @return array<string, mixed>|null */
    public static function summary(): ?array
    {
        $latest = static::latestEntry();
        if (! $latest) {
            return null;
        }

        $yesterday = DailyAssetPosition::where('entry_date', now()->subDay()->toDateString())->first() ?? $latest;

        $stockMovementDifference = round(abs((float) $latest->closing_stock - $latest->calculateClosingStock()), 3);
        $totalCashBank = $latest->calculateTotalCash();

        $reconciliation = static::reconciliation();
        $overallDiffPct = $reconciliation['closing_stock']['diff_pct'] ?? null;

        return [
            'entry_date' => $latest->entry_date,
            'yesterday_sales_weight' => (float) $yesterday->sales,
            'closing_stock_weight' => (float) $latest->closing_stock,
            'net_weight' => (float) $latest->net_weight,
            'total_cash_bank' => $totalCashBank,
            'available_cash' => (float) $latest->available_cash,
            'cash_for_gb' => (float) $latest->cash_for_gb,
            'supplier_hutang' => (float) $latest->supplier_hutang,
            'supplier_overpaid' => (float) $latest->supplier_overpaid,
            'stock_movement_difference' => $stockMovementDifference,
            'loss_from_melting' => (float) $latest->loss_from_melting,
            'jemisys_vs_accountant_diff_pct' => $overallDiffPct,
        ];
    }

    /**
     * Siri harian (menaik ikut tarikh) utk chart trend - N hari kebelakangan drpd rekod terkini.
     *
     * @return Collection<int, array{entry_date: Carbon, closing_stock: float, net_weight: float, sales: float, total_cash: float, cash_recorded: bool, available_cash: float, cash_for_gb: float, supplier_hutang: float, supplier_overpaid: float}>
     */
    public static function trend(int $days = 30): Collection
    {
        return static::mapTrend(
            DailyAssetPosition::query()->orderByDesc('entry_date')->limit($days)->get()
        );
    }

    /**
     * Sama seperti trend() tetapi ikut TEMPOH kalendar: rekod dlm $days hari kebelakangan drpd rekod terkini
     * (trend() pula ambil N rekod terakhir, tak semestinya N hari kalau ada hari tiada rekod).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function trendWithinDays(int $days): Collection
    {
        $latest = DailyAssetPosition::query()->max('entry_date');

        if ($latest === null) {
            return collect();
        }

        $since = Carbon::parse($latest)->subDays($days - 1)->toDateString();

        return static::mapTrend(
            DailyAssetPosition::query()->where('entry_date', '>=', $since)->orderByDesc('entry_date')->get()
        );
    }

    /**
     * @param  Collection<int, DailyAssetPosition>  $records
     * @return Collection<int, array<string, mixed>>
     */
    protected static function mapTrend(Collection $records): Collection
    {
        return $records
            ->sortBy('entry_date')
            ->values()
            ->map(fn (DailyAssetPosition $r) => [
                'entry_date' => $r->entry_date,
                'closing_stock' => (float) $r->closing_stock,
                'net_weight' => (float) $r->net_weight,
                'sales' => (float) $r->sales,
                'total_cash' => $r->calculateTotalCash(),
                'cash_recorded' => $r->calculateTotalCash() > 0,
                'available_cash' => (float) $r->available_cash,
                'cash_for_gb' => (float) $r->cash_for_gb,
                'supplier_hutang' => (float) $r->supplier_hutang,
                'supplier_overpaid' => (float) $r->supplier_overpaid,
            ]);
    }

    /** Harga emas TETAP (RM/g) utk nilaikan gram kepada RM - rujuk config/dashboard.php. 0 = belum diset. */
    public static function goldReferencePrice(): float
    {
        return max(0.0, (float) config('dashboard.daily_asset_position.gold_reference_price_per_gram', 0));
    }

    /**
     * Siri harian dlm RM: emas (Net Weight x harga tetap) + Available Cash = Total Asset Value, serta
     * Hutang Supplier (gram x harga tetap) berbanding Available Cash. Harga TETAP, jadi perubahan
     * jumlah hanya datang drpd perubahan stok, hutang atau cash - bukan harga pasaran.
     *
     * Hari tanpa baki bank/cash dikeyin (Total Cash kasar = 0, cth. accountant hanya isi bahagian emas)
     * ditandakan available_cash/total_value/cash_after_hutang = null (jurang pada graf), BUKAN sifar -
     * kalau tak, graf tunjuk "jatuh" palsu yg mengelirukan penilaian "kalau turun, ada yg tak kena".
     *
     * @return Collection<int, array{entry_date: Carbon, net_weight: float, gold_value: float, available_cash: float|null, total_value: float|null, hutang_value: float, cash_after_hutang: float|null}>
     */
    public static function valueTrend(int $days = 30, bool $withMarketPrice = false): Collection
    {
        $price = static::goldReferencePrice();
        $trend = static::trendWithinDays($days);
        $marketPrices = $withMarketPrice ? static::marketPriceByDate($trend->pluck('entry_date')->all()) : [];

        return $trend->map(function (array $r) use ($price, $marketPrices) {
            $goldValue = round($r['net_weight'] * $price, 2);
            $hutangValue = round($r['supplier_hutang'] * $price, 2);

            $recorded = $r['cash_recorded'];
            $cash = $recorded ? $r['available_cash'] : null;
            $totalCash = $recorded ? $r['total_cash'] : null;
            $marketPrice = $marketPrices[$r['entry_date']->toDateString()] ?? null;

            return [
                'entry_date' => $r['entry_date'],
                'net_weight' => $r['net_weight'],
                'gold_value' => $goldValue,
                'available_cash' => $cash,
                'total_cash' => $totalCash,
                // Net Weight x harga TETAP + Total Cash (kasar).
                'total_value' => $totalCash !== null ? round($goldValue + $totalCash, 2) : null,
                'market_price' => $marketPrice,
                // Net Weight x harga SEBENAR hari tsb + Total Cash (kasar).
                'total_value_market' => $totalCash !== null && $marketPrice !== null
                    ? round($r['net_weight'] * $marketPrice + $totalCash, 2) : null,
                'hutang_value' => $hutangValue,
                'cash_after_hutang' => $cash !== null ? round($cash - $hutangValue, 2) : null,
            ];
        });
    }

    /**
     * Harga emas SEBENAR harian (RM/g) = BuyRate penutup hari kelas 999 drpd JEMiSys (GoldRateByHolderHist).
     * Hari tanpa kemas kini kadar (cth. hujung minggu) guna kadar terakhir yg diketahui (dibawa ke hadapan);
     * hari sebelum sebarang kadar dlm tetingkap = tiada (null). Gagal query = peta kosong (graf tanpa garis
     * pasaran, bukan ralat). Cache 1 jam.
     *
     * @param  array<int, Carbon>  $dates
     * @return array<string, float> tarikh (Y-m-d) => RM/g
     */
    public static function marketPriceByDate(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $first = collect($dates)->min()->copy()->subDays(10)->toDateString();
        $last = collect($dates)->max()->toDateString();

        try {
            $rates = Cache::remember("dap_market_price_{$first}_{$last}", 3600, function () use ($first, $last) {
                $rates = [];

                // GoldRateAnalytics hadkan granulariti harian kpd MAX_DAILY_RANGE_DAYS - pecah julat panjang.
                for ($from = Carbon::parse($first); $from->toDateString() <= $last; $from->addDays(GoldRateAnalytics::MAX_DAILY_RANGE_DAYS)) {
                    $to = $from->copy()->addDays(GoldRateAnalytics::MAX_DAILY_RANGE_DAYS - 1);
                    $to = $to->toDateString() > $last ? Carbon::parse($last) : $to;

                    $result = app(GoldRateAnalytics::class)->trend($from->toDateString(), $to->toDateString(), [static::MARKET_PRICE_CLASS], null, 'day');

                    foreach ($result['classes'][0]['points'] ?? [] as $p) {
                        if ($p['buy_close'] !== null) {
                            $rates[$p['period']] = (float) $p['buy_close'];
                        }
                    }
                }

                return $rates;
            });
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        ksort($rates);
        $byDate = [];
        $carry = null;

        for ($d = Carbon::parse($first); $d->toDateString() <= $last; $d->addDay()) {
            $key = $d->toDateString();
            $carry = $rates[$key] ?? $carry;

            if ($carry !== null) {
                $byDate[$key] = $carry;
            }
        }

        return $byDate;
    }

    /**
     * Used Gold tersedia (kategori "Used Gold at HQ" dlm Physical Gold Report Approved, gram tulen, TANPA baris YS/KIV) vs
     * Hutang Supplier (gram, drpd rekod harian accountant) - kedua-duanya gram, jadi tiada harga emas
     * terlibat. Tarikh gabungan kedua-dua sumber; nilai yg tiada pd tarikh tsb = null (laporan fizikal tak harian).
     *
     * @return Collection<int, array{date: string, used_gold: float|null, supplier_hutang: float|null}>
     */
    public static function usedGoldVsHutangTrend(int $days = 30): Collection
    {
        $hutang = static::trendWithinDays($days)
            ->mapWithKeys(fn (array $r) => [$r['entry_date']->toDateString() => $r['supplier_hutang']]);

        $usedGold = PhysicalGoldReport::query()
            ->where('status', PhysicalGoldReport::STATUS_APPROVED)
            ->where('report_date', '>=', now()->subDays($days)->startOfDay())
            ->with('lines.category', 'lines.purity')
            ->get()
            ->mapWithKeys(fn (PhysicalGoldReport $r) => [
                $r->report_date->toDateString() => PhysicalGoldReportCalculator::usedGoldPureWeightExcludingYs($r),
            ]);

        return $hutang->keys()->merge($usedGold->keys())->unique()->sort()->values()
            ->map(fn (string $date) => [
                'date' => $date,
                'used_gold' => $usedGold->get($date),
                'supplier_hutang' => $hutang->get($date),
            ]);
    }

    /**
     * Opening stock TOLAK hutang supplier (campur overpaid) vs Net Weight penutup (gram) per hari - beza
     * = pergerakan bersih sehari. Formula opening sama dgn net_weight (closing - hutang + overpaid).
     *
     * @return Collection<int, array{entry_date: Carbon, opening_net: float, net_weight: float, change: float}>
     */
    public static function openingNetOfHutangTrend(int $days = 30): Collection
    {
        return DailyAssetPosition::query()
            ->orderByDesc('entry_date')
            ->limit($days)
            ->get()
            ->sortBy('entry_date')
            ->values()
            ->map(function (DailyAssetPosition $r) {
                $openingNet = round((float) $r->opening_stock_weight - (float) $r->supplier_hutang + (float) $r->supplier_overpaid, 2);

                return [
                    'entry_date' => $r->entry_date,
                    'opening_net' => $openingNet,
                    'net_weight' => (float) $r->net_weight,
                    'change' => round((float) $r->net_weight - $openingNet, 2),
                ];
            });
    }

    /**
     * Banding rekod accountant TERKINI vs proksi JEMiSys - rujuk NOTA JUJUR atas kelas ni.
     *
     * @return array<string, array{label: string, jemisys: float|null, accountant: float, diff: float|null, diff_pct: float|null, status: string}>
     */
    public static function reconciliation(): array
    {
        $latest = static::latestEntry();
        if (! $latest) {
            return [];
        }

        return Cache::rememberForever('daily_asset_position_reconciliation', function () use ($latest) {
            return retry(6, fn () => static::compute($latest), 800);
        });
    }

    protected static function compute(DailyAssetPosition $latest): array
    {
        $date = $latest->entry_date->toDateString();

        $jemisysSales = (float) InventoryPiece::query()->realVendor()
            ->whereDate('SalesDate', $date)->sum('GoldWeight');

        $jemisysNewStock = (float) InventoryPiece::query()->realVendor()
            ->whereDate('PurchDate', $date)->sum('GoldWeight');

        $jemisysClosingStock = (float) InventoryPiece::query()->onHand()->realVendor()->sum('GoldWeight');

        $jemisysBranchTotal = (float) InventoryPiece::query()->onHand()->realVendor()->physicalStore()->sum('GoldWeight');

        return [
            'sales' => static::compare('Sales Weight', $jemisysSales, (float) $latest->sales),
            'new_stock' => static::compare('New Stock', $jemisysNewStock, (float) $latest->new_stock),
            'closing_stock' => static::compare('Closing Stock', $jemisysClosingStock, (float) $latest->closing_stock),
            'branch_total' => static::compare('Branch Stock Total', $jemisysBranchTotal, (float) $latest->closing_stock),
        ];
    }

    protected static function compare(string $label, float $jemisys, float $accountant): array
    {
        $diff = round($accountant - $jemisys, 3);
        $denominator = abs($jemisys) > 0.0 ? abs($jemisys) : max(abs($accountant), 1.0);
        $diffPct = round((abs($diff) / $denominator) * 100, 2);

        $yellowThreshold = (float) config('dashboard.daily_asset_position.reconciliation_yellow_pct', 2.0);
        $redThreshold = (float) config('dashboard.daily_asset_position.reconciliation_red_pct', 5.0);

        $status = match (true) {
            $diffPct >= $redThreshold => self::STATUS_RED,
            $diffPct >= $yellowThreshold => self::STATUS_YELLOW,
            default => self::STATUS_GREEN,
        };

        return [
            'label' => $label,
            'jemisys' => $jemisys,
            'accountant' => $accountant,
            'diff' => $diff,
            'diff_pct' => $diffPct,
            'status' => $status,
        ];
    }
}
