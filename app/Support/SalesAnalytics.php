<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Analitik jualan JEMiSys (TblSalesInvoice + TblSalesInvoiceDetail) - dibaca sahaja, live ke
 * 'jemisys'. Digunakan oleh tool MCP (Sales Summary, Gross Margin, Top Products).
 *
 * Peraturan jualan sah (disahkan drpd data sebenar, Sep 2026):
 * - H.Status '0' = sah, 'V' = batal (DIKECUALIKAN). Tanpa tapisan ni jumlah Sep terlebih ~RM519k.
 * - H.DocType 'SI' = jualan, 'SR' = pemulangan (NetAmount negatif). Pemulangan dilaporkan
 *   berasingan; margin dikira atas baris SI sahaja sbb kos baris SR tidak dibalikkan (~RM0.10).
 * - Jumlah NetAmount header = jumlah NetPrice detail (disahkan sepadan tepat utk SI).
 * - D.TotalCost = kos baris (sepadan GoldCost + McCostTotal), BUKAN per unit.
 *
 * Semua query: tapisan tarikh wajib (maks MAX_RANGE_DAYS), WITH (NOLOCK) supaya tak menghalang
 * POS, dan had masa query di SQL Server (rujuk SqlServerQueryTimeout).
 */
class SalesAnalytics
{
    public const MAX_RANGE_DAYS = 366;

    public const MAX_ROWS = 50;

    private const CONNECTION = 'jemisys';

    /**
     * @param  'store'|'day'|'month'|'total'  $groupBy
     * @return array<int, array{period: string, sales_net: float, returns_net: float, net_sales: float, invoice_count: int, return_count: int}>
     */
    public function summary(string $dateFrom, string $dateTo, ?string $storeCode, string $groupBy): array
    {
        self::assertDateRange($dateFrom, $dateTo);

        // 'total' dikira di PHP drpd kumpulan cawangan: query tanpa GROUP BY (agregat skalar)
        // disahkan >4 minit atas data sebenar, manakala GROUP BY cawangan siap ~2.5s.
        $queryGroupBy = $groupBy === 'total' ? 'store' : $groupBy;

        $groupExpression = self::periodExpression($queryGroupBy);

        $rows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => $this->invoices($dateFrom, $dateTo, $storeCode)
            ->selectRaw("{$groupExpression} AS period")
            ->selectRaw("SUM(CASE WHEN H.DocType = 'SI' THEN H.NetAmount ELSE 0 END) AS sales_net")
            ->selectRaw("SUM(CASE WHEN H.DocType = 'SR' THEN H.NetAmount ELSE 0 END) AS returns_net")
            ->selectRaw("SUM(CASE WHEN H.DocType = 'SI' THEN 1 ELSE 0 END) AS invoice_count")
            ->selectRaw("SUM(CASE WHEN H.DocType = 'SR' THEN 1 ELSE 0 END) AS return_count")
            ->groupByRaw($groupExpression)
            ->orderBy($queryGroupBy === 'store' ? 'sales_net' : 'period', $queryGroupBy === 'store' ? 'desc' : 'asc')
            ->limit(self::MAX_ROWS * 8)
            ->get());

        if ($groupBy === 'total') {
            $rows = collect([(object) [
                'period' => 'total',
                'sales_net' => $rows->sum('sales_net'),
                'returns_net' => $rows->sum('returns_net'),
                'invoice_count' => $rows->sum('invoice_count'),
                'return_count' => $rows->sum('return_count'),
            ]]);
        }

        return $rows->map(fn ($r) => [
            'period' => trim((string) $r->period),
            'sales_net' => round((float) $r->sales_net, 2),
            'returns_net' => round((float) $r->returns_net, 2),
            'net_sales' => round((float) $r->sales_net + (float) $r->returns_net, 2),
            'invoice_count' => (int) $r->invoice_count,
            'return_count' => (int) $r->return_count,
        ])->all();
    }

    /**
     * Piece terjual (jualan SI sahaja, pemulangan tak ditolak - tanda Qty pada baris SR tak konsisten).
     * `pieces_sold` = SUM(Qty) baris bertanda InternalCode (design jewelry; disahkan sepadan tepat dgn piece
     * terjual dlm cermin inventori, 1-7 Okt 2026). `non_design_qty` = Qty baris TANPA InternalCode
     * (token/misc) - bukan piece jewelry, dilaporkan berasingan.
     *
     * @param  'store'|'day'|'month'|'total'  $groupBy
     * @return array<int, array{period: string, pieces_sold: int, non_design_qty: int}>
     */
    public function pieces(string $dateFrom, string $dateTo, ?string $storeCode, string $groupBy): array
    {
        self::assertDateRange($dateFrom, $dateTo);

        // 'total' dikira di PHP drpd kumpulan cawangan (agregat skalar tanpa GROUP BY terlalu perlahan).
        $queryGroupBy = $groupBy === 'total' ? 'store' : $groupBy;
        $groupExpression = self::periodExpression($queryGroupBy);

        $rows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => $this->saleLines($dateFrom, $dateTo, $storeCode)
            ->selectRaw("{$groupExpression} AS period")
            ->selectRaw("SUM(CASE WHEN ISNULL(RTRIM(D.InternalCode), '') <> '' THEN D.Qty ELSE 0 END) AS pieces_sold")
            ->selectRaw("SUM(CASE WHEN ISNULL(RTRIM(D.InternalCode), '') = '' THEN D.Qty ELSE 0 END) AS non_design_qty")
            ->groupByRaw($groupExpression)
            ->orderBy($queryGroupBy === 'store' ? 'pieces_sold' : 'period', $queryGroupBy === 'store' ? 'desc' : 'asc')
            ->limit(self::MAX_ROWS * 8)
            ->get());

        if ($groupBy === 'total') {
            $rows = collect([(object) [
                'period' => 'total',
                'pieces_sold' => $rows->sum('pieces_sold'),
                'non_design_qty' => $rows->sum('non_design_qty'),
            ]]);
        }

        return $rows->map(fn ($r) => [
            'period' => trim((string) $r->period),
            'pieces_sold' => (int) round((float) $r->pieces_sold),
            'non_design_qty' => (int) round((float) $r->non_design_qty),
        ])->all();
    }

    /**
     * Gabung baris jumlah (summary) dan baris piece (pieces) ikut `period` - tempoh yg ada pada satu pihak
     * sahaja diisi 0 pada pihak satu lagi. Susunan: cawangan -> jualan bersih menurun, hari/bulan -> menaik.
     *
     * @param  array<int, array<string, mixed>>  $amountRows
     * @param  array<int, array<string, mixed>>  $pieceRows
     * @return array<int, array<string, mixed>>
     */
    public static function mergeByPeriod(array $amountRows, array $pieceRows, string $groupBy): array
    {
        $merged = [];

        foreach ($amountRows as $row) {
            $merged[$row['period']] = $row + ['pieces_sold' => 0, 'non_design_qty' => 0];
        }

        foreach ($pieceRows as $row) {
            $merged[$row['period']] ??= [
                'period' => $row['period'],
                'sales_net' => 0.0,
                'returns_net' => 0.0,
                'net_sales' => 0.0,
                'invoice_count' => 0,
                'return_count' => 0,
            ];
            $merged[$row['period']]['pieces_sold'] = $row['pieces_sold'];
            $merged[$row['period']]['non_design_qty'] = $row['non_design_qty'];
        }

        $rows = array_values($merged);

        usort($rows, fn (array $a, array $b) => in_array($groupBy, ['day', 'month'], true)
            ? strcmp($a['period'], $b['period'])
            : [$b['net_sales'], $a['period']] <=> [$a['net_sales'], $b['period']]);

        return $rows;
    }

    /** Ungkapan SQL bagi `period` (cawangan/hari/bulan) - dikongsi summary() dan pieces(). */
    private static function periodExpression(string $groupBy): string
    {
        return match ($groupBy) {
            'store' => 'RTRIM(H.StoreCode)',
            'day' => 'CONVERT(char(10), H.InvoiceDate, 23)',
            'month' => 'LEFT(CONVERT(char(10), H.InvoiceDate, 23), 7)',
            default => throw new InvalidArgumentException("group_by tidak sah: {$groupBy}"),
        };
    }

    /**
     * Margin kasar atas baris jualan (SI) - hasil = SUM(NetPrice), kos = SUM(TotalCost).
     * `lines_without_cost` = baris berkos 0 (cth. item misc/perkhidmatan) - menaikkan margin
     * secara palsu, jadi dilaporkan supaya pemanggil boleh nilai kebolehpercayaan angka.
     *
     * @param  'store'|'category'  $groupBy
     * @return array<int, array<string, mixed>>
     */
    public function grossMargin(string $dateFrom, string $dateTo, ?string $storeCode, string $groupBy): array
    {
        self::assertDateRange($dateFrom, $dateTo);

        $groupExpression = match ($groupBy) {
            'store' => 'RTRIM(H.StoreCode)',
            'category' => 'RTRIM(D.CategoryCode)',
            default => throw new InvalidArgumentException("group_by tidak sah: {$groupBy}"),
        };

        $rows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => $this->saleLines($dateFrom, $dateTo, $storeCode)
            ->selectRaw("{$groupExpression} AS group_key")
            ->selectRaw('SUM(D.NetPrice) AS revenue')
            ->selectRaw('SUM(D.TotalCost) AS cost')
            ->selectRaw('SUM(D.Qty) AS qty')
            ->selectRaw('SUM(CASE WHEN D.TotalCost = 0 THEN 1 ELSE 0 END) AS lines_without_cost')
            ->groupByRaw($groupExpression)
            ->orderByDesc('revenue')
            ->limit(self::MAX_ROWS)
            ->get());

        $totalRevenue = (float) $rows->sum('revenue');
        $descriptions = $groupBy === 'category' ? $this->categoryDescriptions($rows->pluck('group_key')->all()) : [];

        return $rows->map(function ($r) use ($totalRevenue, $descriptions, $groupBy) {
            $revenue = (float) $r->revenue;
            $cost = (float) $r->cost;
            $key = trim((string) $r->group_key);

            return [
                'key' => $key,
                'label' => $groupBy === 'category' ? ($descriptions[$key] ?? $key) : $key,
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'gross_margin' => round($revenue - $cost, 2),
                'margin_pct' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 2) : null,
                'revenue_share_pct' => $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 2) : null,
                'qty' => (float) $r->qty,
                'lines_without_cost' => (int) $r->lines_without_cost,
            ];
        })->all();
    }

    /**
     * Design terlaris ikut InternalCode (baris tanpa InternalCode dikecualikan - cth. kategori
     * TKG token/misc). Pemulangan tak dikira.
     *
     * @param  'qty'|'revenue'|'margin'  $orderBy
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(string $dateFrom, string $dateTo, ?string $storeCode, ?string $categoryCode, string $orderBy, int $limit): array
    {
        self::assertDateRange($dateFrom, $dateTo);

        $orderColumn = match ($orderBy) {
            'qty' => 'qty',
            'revenue' => 'revenue',
            'margin' => 'gross_margin',
            default => throw new InvalidArgumentException("order_by tidak sah: {$orderBy}"),
        };

        $rows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => $this->saleLines($dateFrom, $dateTo, $storeCode)
            ->whereRaw("RTRIM(D.InternalCode) <> ''")
            ->when($categoryCode, fn ($q, $code) => $q->whereRaw('RTRIM(D.CategoryCode) = ?', [trim($code)]))
            ->selectRaw('RTRIM(D.InternalCode) AS internal_code')
            ->selectRaw('MAX(RTRIM(D.CategoryCode)) AS category_code')
            ->selectRaw('SUM(D.Qty) AS qty')
            ->selectRaw('SUM(D.NetPrice) AS revenue')
            ->selectRaw('SUM(D.NetPrice - D.TotalCost) AS gross_margin')
            ->groupByRaw('RTRIM(D.InternalCode)')
            ->orderByDesc($orderColumn)
            ->limit(min($limit, self::MAX_ROWS))
            ->get());

        return $rows->map(fn ($r) => [
            'internal_code' => trim((string) $r->internal_code),
            'category_code' => trim((string) $r->category_code),
            'qty' => (float) $r->qty,
            'revenue' => round((float) $r->revenue, 2),
            'gross_margin' => round((float) $r->gross_margin, 2),
            'margin_pct' => (float) $r->revenue > 0 ? round((float) $r->gross_margin / (float) $r->revenue * 100, 2) : null,
        ])->all();
    }

    /** Tarikh sah, dateFrom <= dateTo, dan julat tak melebihi MAX_RANGE_DAYS. */
    public static function assertDateRange(string $dateFrom, string $dateTo): void
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();

        if ($from->gt($to)) {
            throw new InvalidArgumentException('date_from mesti sebelum atau sama dengan date_to.');
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Julat tarikh maksimum '.self::MAX_RANGE_DAYS.' hari.');
        }
    }

    private function invoices(string $dateFrom, string $dateTo, ?string $storeCode): Builder
    {
        self::assertDateRange($dateFrom, $dateTo);

        return DB::connection(self::CONNECTION)->table(DB::raw('TblSalesInvoice AS H WITH (NOLOCK)'))
            ->where('H.Status', '0')
            ->whereIn('H.DocType', ['SI', 'SR'])
            ->where('H.InvoiceDate', '>=', Carbon::parse($dateFrom)->toDateString())
            ->where('H.InvoiceDate', '<', Carbon::parse($dateTo)->addDay()->toDateString())
            ->when($storeCode, fn ($q, $code) => $q->whereRaw('RTRIM(H.StoreCode) = ?', [trim($code)]));
    }

    /** Baris detail jualan (SI sahaja) yg join header sah. */
    private function saleLines(string $dateFrom, string $dateTo, ?string $storeCode): Builder
    {
        return $this->invoices($dateFrom, $dateTo, $storeCode)
            ->where('H.DocType', 'SI')
            ->join(DB::raw('TblSalesInvoiceDetail AS D WITH (NOLOCK)'), function ($join) {
                $join->on('D.InvoiceNo', '=', 'H.InvoiceNo')->on('D.StoreCode', '=', 'H.StoreCode');
            });
    }

    /**
     * @param  array<int, string|null>  $codes
     * @return array<string, string> kod kategori => Description
     */
    private function categoryDescriptions(array $codes): array
    {
        $trimmed = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $codes), fn ($c) => $c !== ''));

        if ($trimmed === []) {
            return [];
        }

        return DB::connection(self::CONNECTION)->table(DB::raw('TblCategory WITH (NOLOCK)'))
            ->whereIn(DB::raw('RTRIM(CategoryCode)'), $trimmed)
            ->get(['CategoryCode', 'Description'])
            ->mapWithKeys(fn ($r) => [trim($r->CategoryCode) => trim((string) $r->Description)])
            ->all();
    }
}
