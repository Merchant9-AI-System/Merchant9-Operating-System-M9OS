<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data harian utk formula hedge Finance - baca sahaja, LIVE ke 'jemisys' (tiada mirror tempatan):
 *
 *   hedge (g tulen) = (berat jualan semua cawangan x sales_purity) - (berat buyback x buyback_purity)
 *
 * Padanan dgn tangkapan skrin & mesej Finance disahkan pd data 4 Nov 2026 (potongan ~5 ptg):
 * - Jualan = TblSalesInvoice DocType 'SI', Status '0' (baris TblSalesInvoiceDetail; SUM(GoldWeight)).
 * - Jenis emas laporan: kelas 916* -> '916'; 999 & 9999 -> '999'; kelas lain berat 0 (cth. PERAK 925) ->
 *   'Jewellery'; kelas lain berat > 0 -> kod kelas sendiri. Kedai case-insensitive (web == WEB).
 * - Buyback = TblTradeIn Status '0', DocType dlm config dashboard.hedge.buyback_doc_types (TI + TC) -
 *   TI sahaja tak cukup mencapai 710.24g pd waktu potong sama dgn jualan; TI+TC padan (andaian: sahkan
 *   dgn Finance).
 * - Faktor 0.935/0.900 ialah andaian tetap Finance (bukan purata sebenar), rujuk config.
 *
 * Keselamatan query: tapisan tarikh wajib, WITH (NOLOCK), had masa (rujuk SqlServerQueryTimeout).
 */
class DailyHedgeFigure
{
    private const CONNECTION = 'jemisys';

    /**
     * @param  string|null  $asOf  potong pd masa ini (cth. '2026-11-04 17:20') - utk banding dgn tangkapan
     *                             skrin separuh hari; null = hari penuh.
     * @return array<string, mixed>
     */
    public function forDate(string $date, ?string $asOf = null): array
    {
        $from = Carbon::parse($date)->toDateString();
        $to = Carbon::parse($date)->addDay()->toDateString();
        $cutoff = $asOf ? Carbon::parse($asOf)->format('Y-m-d H:i:s') : null;

        $salesRows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => DB::connection(self::CONNECTION)->select(
            'SELECT UPPER(RTRIM(H.StoreCode)) AS store, RTRIM(D.ClassCode) AS cls,
                    SUM(D.Qty) AS qty, SUM(D.GoldWeight) AS gold_weight, SUM(D.NetPrice) AS total_price, SUM(D.TotalCost) AS cost
             FROM TblSalesInvoice H WITH (NOLOCK)
             JOIN TblSalesInvoiceDetail D WITH (NOLOCK) ON D.InvoiceNo = H.InvoiceNo AND D.StoreCode = H.StoreCode
             WHERE H.Status = ? AND H.DocType = ? AND H.InvoiceDate >= ? AND H.InvoiceDate < ?'.($cutoff ? ' AND D.CreatedDate <= ?' : '').'
             GROUP BY UPPER(RTRIM(H.StoreCode)), RTRIM(D.ClassCode)',
            array_filter(['0', 'SI', $from, $to, $cutoff], fn ($v) => $v !== null),
        ), SqlServerQueryTimeout::HEAVY_SECONDS);

        $docTypes = array_values((array) config('dashboard.hedge.buyback_doc_types', ['TI', 'TC']));
        $placeholders = implode(',', array_fill(0, count($docTypes), '?'));

        $buyback = SqlServerQueryTimeout::run(self::CONNECTION, fn () => DB::connection(self::CONNECTION)->selectOne(
            "SELECT SUM(TotalGoldWeight) AS weight, COUNT(*) AS docs
             FROM TblTradeIn WITH (NOLOCK)
             WHERE Status = ? AND RTRIM(DocType) IN ({$placeholders}) AND TransDate >= ? AND TransDate < ?".($cutoff ? ' AND CreatedDate <= ?' : ''),
            array_filter(['0', ...$docTypes, $from, $to, $cutoff], fn ($v) => $v !== null),
        ), SqlServerQueryTimeout::HEAVY_SECONDS);

        return ['date' => $from, 'as_of' => $cutoff]
            + self::fromRows(
                array_map(fn ($r) => [
                    'store' => (string) $r->store,
                    'class_code' => trim((string) $r->cls),
                    'qty' => (float) $r->qty,
                    'gold_weight' => (float) $r->gold_weight,
                    'total_price' => (float) $r->total_price,
                    'cost' => (float) $r->cost,
                ], $salesRows),
                (float) ($buyback->weight ?? 0),
                (int) ($buyback->docs ?? 0),
            );
    }

    /**
     * Siri harian (menaik) utk graf: berat jualan, buyback & hedge tulen setiap hari dlm julat. Dua query
     * berkumpul ikut tarikh (bukan satu query sehari). Hari tanpa jualan DAN tanpa buyback tidak disenaraikan.
     * Hari terkini (hari ini) boleh separa - invois masih masuk. Cache 15 minit.
     *
     * @return Collection<int, array{date: string, sales_weight: float, buyback_weight: float, sales_pure_weight: float, buyback_pure_weight: float, hedge_pure_weight: float}>
     */
    public function dailySeries(string $dateFrom, string $dateTo): Collection
    {
        SalesAnalytics::assertDateRange($dateFrom, $dateTo);

        $from = Carbon::parse($dateFrom)->toDateString();
        $to = Carbon::parse($dateTo)->toDateString();
        $toExclusive = Carbon::parse($dateTo)->addDay()->toDateString();
        $docTypes = array_values((array) config('dashboard.hedge.buyback_doc_types', ['TI', 'TC']));
        $salesPurity = (float) config('dashboard.hedge.sales_purity', 0.935);
        $buybackPurity = (float) config('dashboard.hedge.buyback_purity', 0.900);

        $key = 'daily_hedge_series_'.md5(json_encode([$from, $to, $docTypes]));

        $raw = Cache::remember($key, now()->addMinutes(15), function () use ($from, $toExclusive, $docTypes) {
            $sales = SqlServerQueryTimeout::run(self::CONNECTION, fn () => DB::connection(self::CONNECTION)->select(
                'SELECT CONVERT(char(10), H.InvoiceDate, 23) AS d, SUM(D.GoldWeight) AS weight
                 FROM TblSalesInvoice H WITH (NOLOCK)
                 JOIN TblSalesInvoiceDetail D WITH (NOLOCK) ON D.InvoiceNo = H.InvoiceNo AND D.StoreCode = H.StoreCode
                 WHERE H.Status = ? AND H.DocType = ? AND H.InvoiceDate >= ? AND H.InvoiceDate < ?
                 GROUP BY CONVERT(char(10), H.InvoiceDate, 23)',
                ['0', 'SI', $from, $toExclusive],
            ), SqlServerQueryTimeout::HEAVY_SECONDS);

            $placeholders = implode(',', array_fill(0, count($docTypes), '?'));

            $buyback = SqlServerQueryTimeout::run(self::CONNECTION, fn () => DB::connection(self::CONNECTION)->select(
                "SELECT CONVERT(char(10), TransDate, 23) AS d, SUM(TotalGoldWeight) AS weight
                 FROM TblTradeIn WITH (NOLOCK)
                 WHERE Status = ? AND RTRIM(DocType) IN ({$placeholders}) AND TransDate >= ? AND TransDate < ?
                 GROUP BY CONVERT(char(10), TransDate, 23)",
                ['0', ...$docTypes, $from, $toExclusive],
            ), SqlServerQueryTimeout::HEAVY_SECONDS);

            return [
                'sales' => collect($sales)->mapWithKeys(fn ($r) => [(string) $r->d => (float) $r->weight])->all(),
                'buyback' => collect($buyback)->mapWithKeys(fn ($r) => [(string) $r->d => (float) $r->weight])->all(),
            ];
        });

        return self::seriesFromWeights($raw['sales'], $raw['buyback'], $salesPurity, $buybackPurity);
    }

    /**
     * Logik tanpa database (boleh diuji terus).
     *
     * @param  array<string, float>  $salesByDate
     * @param  array<string, float>  $buybackByDate
     * @return Collection<int, array<string, float|string>>
     */
    public static function seriesFromWeights(array $salesByDate, array $buybackByDate, float $salesPurity, float $buybackPurity): Collection
    {
        return collect(array_keys($salesByDate))->merge(array_keys($buybackByDate))->unique()->sort()->values()
            ->map(function (string $date) use ($salesByDate, $buybackByDate, $salesPurity, $buybackPurity) {
                $sales = (float) ($salesByDate[$date] ?? 0.0);
                $buyback = (float) ($buybackByDate[$date] ?? 0.0);
                $salesPure = round($sales * $salesPurity, 2);
                $buybackPure = round($buyback * $buybackPurity, 2);

                return [
                    'date' => $date,
                    'sales_weight' => round($sales, 2),
                    'buyback_weight' => round($buyback, 2),
                    'sales_pure_weight' => $salesPure,
                    'buyback_pure_weight' => $buybackPure,
                    'hedge_pure_weight' => round($salesPure - $buybackPure, 2),
                ];
            });
    }

    /**
     * Logik tanpa database (boleh diuji terus).
     *
     * @param  array<int, array{store: string, class_code: string, qty: float, gold_weight: float, total_price: float, cost: float}>  $salesRows
     * @return array<string, mixed>
     */
    public static function fromRows(array $salesRows, float $buybackWeight, int $buybackDocs = 0, ?float $salesPurity = null, ?float $buybackPurity = null): array
    {
        $salesPurity ??= (float) config('dashboard.hedge.sales_purity', 0.935);
        $buybackPurity ??= (float) config('dashboard.hedge.buyback_purity', 0.900);

        $weightByClass = [];
        foreach ($salesRows as $row) {
            $weightByClass[$row['class_code']] = ($weightByClass[$row['class_code']] ?? 0.0) + $row['gold_weight'];
        }

        $stores = [];
        $totals = ['qty' => 0.0, 'gold_weight' => 0.0, 'total_price' => 0.0, 'cost' => 0.0];

        foreach ($salesRows as $row) {
            $type = self::goldType($row['class_code'], $weightByClass[$row['class_code']] ?? 0.0);
            $store = strtoupper(trim($row['store']));

            $line = &$stores[$store][$type];
            $line ??= ['qty' => 0.0, 'gold_weight' => 0.0, 'total_price' => 0.0, 'cost' => 0.0];

            foreach (['qty', 'gold_weight', 'total_price', 'cost'] as $key) {
                $line[$key] += $row[$key];
                $totals[$key] += $row[$key];
            }
            unset($line);
        }

        ksort($stores);

        $storeRows = [];
        foreach ($stores as $store => $types) {
            ksort($types);
            $storeRows[$store] = array_map(fn (array $l) => self::withGp($l), $types);
        }

        $salesPure = round($totals['gold_weight'] * $salesPurity, 2);
        $buybackPure = round($buybackWeight * $buybackPurity, 2);

        return [
            'stores' => $storeRows,
            'totals' => self::withGp($totals),
            'sales_weight' => round($totals['gold_weight'], 2),
            'buyback_weight' => round($buybackWeight, 2),
            'buyback_docs' => $buybackDocs,
            'sales_purity' => $salesPurity,
            'buyback_purity' => $buybackPurity,
            'sales_pure_weight' => $salesPure,
            'buyback_pure_weight' => $buybackPure,
            'hedge_pure_weight' => round($salesPure - $buybackPure, 2),
        ];
    }

    /** Jenis emas utk laporan - rujuk nota kelas. */
    public static function goldType(string $classCode, float $classGoldWeight): string
    {
        $code = strtoupper(trim($classCode));

        return match (true) {
            str_starts_with($code, '916') => '916',
            $code === '999' || $code === '9999' => '999',
            $classGoldWeight <= 0.0 => 'Jewellery',
            default => $code,
        };
    }

    /**
     * @param  array{qty: float, gold_weight: float, total_price: float, cost: float}  $line
     * @return array<string, float|null>
     */
    private static function withGp(array $line): array
    {
        $gp = $line['total_price'] - $line['cost'];

        return [
            'qty' => round($line['qty'], 2),
            'gold_weight' => round($line['gold_weight'], 2),
            'total_price' => round($line['total_price'], 2),
            'cost' => round($line['cost'], 2),
            'gp' => round($gp, 2),
            'gp_pct' => $line['total_price'] > 0 ? round($gp / $line['total_price'] * 100, 2) : null,
        ];
    }
}
