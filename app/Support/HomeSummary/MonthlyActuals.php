<?php

namespace App\Support\HomeSummary;

use App\Support\SqlServerQueryTimeout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Jualan sebenar bulan-ke-tarikh bagi SEMUA cawangan (JEMiSys LIVE, satu query berkumpul): RM (NetPrice) dan
 * gram (GoldWeight) invois jualan sah (Status '0', DocType 'SI') - SAMA sumber/takrif dgn angka "Jualan semalam"
 * pd /home (DailyHedgeFigure), jadi pemulangan (SR) tidak ditolak. Dikongsi semua pengguna (cache 30 minit).
 * Gagal (JEMiSys tak dapat dicapai) -> null (bukan sifar) dan TIDAK dicache.
 */
class MonthlyActuals
{
    private const CACHE_MINUTES = 30;

    /**
     * @return array<string, array{rm: float, grams: float}>|null kod cawangan (huruf besar) => jualan; null kalau gagal
     */
    public function forMonth(Carbon $monthStart, Carbon $through): ?array
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $through = $through->copy()->startOfDay();

        if ($through->lt($monthStart)) {
            return [];   // hari pertama bulan - belum ada hari lengkap
        }

        $endExclusive = $through->copy()->min($monthStart->copy()->endOfMonth()->startOfDay())->addDay();

        // Bulan yang sudah lengkap tidak lagi berubah - cache lebih lama (jualan baharu tak masuk bulan lalu).
        $monthComplete = $endExclusive->gte($monthStart->copy()->endOfMonth()->startOfDay()->addDay());

        try {
            return Cache::remember(
                'monthly_actuals:'.$monthStart->format('Y-m').':'.$endExclusive->toDateString(),
                $monthComplete ? now()->addHours(12) : now()->addMinutes(self::CACHE_MINUTES),
                fn () => $this->query($monthStart, $endExclusive),
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<string, array{rm: float, grams: float}> */
    private function query(Carbon $from, Carbon $toExclusive): array
    {
        $rows = SqlServerQueryTimeout::run('jemisys', fn () => DB::connection('jemisys')->select(
            'SELECT UPPER(RTRIM(H.StoreCode)) AS store, SUM(D.NetPrice) AS rm, SUM(D.GoldWeight) AS grams
             FROM TblSalesInvoice H WITH (NOLOCK)
             JOIN TblSalesInvoiceDetail D WITH (NOLOCK) ON D.InvoiceNo = H.InvoiceNo AND D.StoreCode = H.StoreCode
             WHERE H.Status = ? AND H.DocType = ? AND H.InvoiceDate >= ? AND H.InvoiceDate < ?
             GROUP BY UPPER(RTRIM(H.StoreCode))',
            ['0', 'SI', $from->toDateString(), $toExclusive->toDateString()],
        ), SqlServerQueryTimeout::HEAVY_SECONDS);

        return collect($rows)->mapWithKeys(fn ($r) => [
            (string) $r->store => ['rm' => round((float) $r->rm, 2), 'grams' => round((float) $r->grams, 2)],
        ])->all();
    }
}
