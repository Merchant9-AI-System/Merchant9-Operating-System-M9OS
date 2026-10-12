<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perbelanjaan pembekal drpd invois pembelian FINM9 (tblPurchInvoiceHeader) - dibaca sahaja.
 *
 * PurchInvoiceStatus 'P' = diposting ke lejar (dikira sbg perbelanjaan); '' = belum posting
 * (draf/belum selesai) - dilaporkan berasingan (`unposted_total`), TIDAK dicampur.
 * Data posted terakhir disahkan 27 Ogos 2026 (Sep 2026: hanya invois belum posting).
 *
 * AMARAN: tblAPOpenItem.AmountOutstanding semua 0 - TIDAK boleh digunakan utk baki hutang,
 * jadi tool ni hanya laporkan perbelanjaan (invois), bukan hutang tertunggak.
 */
class VendorSpendAnalytics
{
    public const MAX_ROWS = 50;

    private const CONNECTION = 'finm9';

    /**
     * @return array{vendors: array<int, array<string, mixed>>, posted_total: float, unposted_total: float}
     */
    public function spend(string $dateFrom, string $dateTo, ?string $vendorCode, int $limit): array
    {
        SalesAnalytics::assertDateRange($dateFrom, $dateTo);

        $rows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => DB::connection(self::CONNECTION)
            ->table(DB::raw('tblPurchInvoiceHeader AS P WITH (NOLOCK)'))
            ->where('P.PurchInvoiceDate', '>=', Carbon::parse($dateFrom)->toDateString())
            ->where('P.PurchInvoiceDate', '<', Carbon::parse($dateTo)->addDay()->toDateString())
            ->when($vendorCode, fn ($q, $code) => $q->whereRaw('RTRIM(P.VendorCode) = ?', [trim($code)]))
            ->selectRaw('RTRIM(P.VendorCode) AS vendor_code')
            ->selectRaw("SUM(CASE WHEN RTRIM(P.PurchInvoiceStatus) = 'P' THEN P.PurchInvoiceTotal ELSE 0 END) AS posted_total")
            ->selectRaw("SUM(CASE WHEN RTRIM(P.PurchInvoiceStatus) = 'P' THEN 1 ELSE 0 END) AS posted_invoices")
            ->selectRaw("SUM(CASE WHEN RTRIM(P.PurchInvoiceStatus) <> 'P' THEN P.PurchInvoiceTotal ELSE 0 END) AS unposted_total")
            ->selectRaw("SUM(CASE WHEN RTRIM(P.PurchInvoiceStatus) <> 'P' THEN 1 ELSE 0 END) AS unposted_invoices")
            ->groupByRaw('RTRIM(P.VendorCode)')
            ->orderByDesc('posted_total')
            ->limit(min($limit, self::MAX_ROWS))
            ->get());

        $names = $this->vendorNames($rows->pluck('vendor_code')->all());

        return [
            'vendors' => $rows->map(fn ($r) => [
                'vendor_code' => trim((string) $r->vendor_code),
                'vendor_name' => $names[trim((string) $r->vendor_code)] ?? null,
                'posted_total' => round((float) $r->posted_total, 2),
                'posted_invoices' => (int) $r->posted_invoices,
                'unposted_total' => round((float) $r->unposted_total, 2),
                'unposted_invoices' => (int) $r->unposted_invoices,
            ])->all(),
            'posted_total' => round((float) $rows->sum('posted_total'), 2),
            'unposted_total' => round((float) $rows->sum('unposted_total'), 2),
        ];
    }

    /**
     * @param  array<int, string|null>  $codes
     * @return array<string, string>
     */
    private function vendorNames(array $codes): array
    {
        $trimmed = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $codes), fn ($c) => $c !== ''));

        if ($trimmed === []) {
            return [];
        }

        return DB::connection(self::CONNECTION)->table(DB::raw('tblVendor WITH (NOLOCK)'))
            ->whereIn(DB::raw('RTRIM(VendorCode)'), $trimmed)
            ->get(['VendorCode', 'Description'])
            ->mapWithKeys(fn ($r) => [trim($r->VendorCode) => trim((string) $r->Description)])
            ->all();
    }
}
