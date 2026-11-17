<?php

namespace App\Support\HomeSummary;

use App\Support\SqlServerQueryTimeout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Jualan bulan-ke-tarikh bagi SEMUA cawangan dipecah ikut staf (JEMiSys LIVE, satu query berkumpul): setiap baris =
 * (cawangan, SalesMan, SalesMan2) dgn bilangan invois (tiket), RM (NetPrice) dan gram (GoldWeight) invois jualan sah
 * (Status '0', DocType 'SI'). Atribusi diambil drpd HEADER invois - disahkan sama dgn baris (0 beza) dan jumlah RM
 * header = jumlah baris. Pemulangan (SR) tidak ditolak (sama takrif dgn "Jualan semalam"). Cache 30 minit
 * (bulan yg sudah lengkap: 12 jam). Gagal -> null (bukan senarai kosong) dan TIDAK dicache.
 */
class StaffSales
{
    private const CACHE_MINUTES = 30;

    /**
     * @return array<int, array{store: string, sm1: string, sm2: string, tickets: int, rm: float, grams: float}>|null
     */
    public function forMonth(Carbon $monthStart, Carbon $through): ?array
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $through = $through->copy()->startOfDay();

        if ($through->lt($monthStart)) {
            return [];   // hari pertama bulan - belum ada hari lengkap
        }

        $endExclusive = $through->copy()->min($monthStart->copy()->endOfMonth()->startOfDay())->addDay();
        $monthComplete = $endExclusive->gte($monthStart->copy()->endOfMonth()->startOfDay()->addDay());

        try {
            return Cache::remember(
                'staff_sales:'.$monthStart->format('Y-m').':'.$endExclusive->toDateString(),
                $monthComplete ? now()->addHours(12) : now()->addMinutes(self::CACHE_MINUTES),
                fn () => $this->query($monthStart, $endExclusive),
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<int, array{store: string, sm1: string, sm2: string, tickets: int, rm: float, grams: float}> */
    private function query(Carbon $from, Carbon $toExclusive): array
    {
        $rows = SqlServerQueryTimeout::run('jemisys', fn () => DB::connection('jemisys')->select(
            "SELECT UPPER(RTRIM(H.StoreCode)) AS store, RTRIM(ISNULL(H.SalesMan, '')) AS sm1, RTRIM(ISNULL(H.SalesMan2, '')) AS sm2,
                    COUNT(DISTINCT H.InvoiceNo) AS tickets, SUM(D.NetPrice) AS rm, SUM(D.GoldWeight) AS grams
             FROM TblSalesInvoice H WITH (NOLOCK)
             JOIN TblSalesInvoiceDetail D WITH (NOLOCK) ON D.InvoiceNo = H.InvoiceNo AND D.StoreCode = H.StoreCode
             WHERE H.Status = ? AND H.DocType = ? AND H.InvoiceDate >= ? AND H.InvoiceDate < ?
             GROUP BY UPPER(RTRIM(H.StoreCode)), RTRIM(ISNULL(H.SalesMan, '')), RTRIM(ISNULL(H.SalesMan2, ''))",
            ['0', 'SI', $from->toDateString(), $toExclusive->toDateString()],
        ), SqlServerQueryTimeout::HEAVY_SECONDS);

        return collect($rows)->map(fn ($r) => [
            'store' => (string) $r->store,
            'sm1' => (string) $r->sm1,
            'sm2' => (string) $r->sm2,
            'tickets' => (int) $r->tickets,
            'rm' => round((float) $r->rm, 2),
            'grams' => round((float) $r->grams, 2),
        ])->all();
    }
}
