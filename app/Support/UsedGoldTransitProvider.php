<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Baki emas trade-in (used gold) yang BELUM diterima/disahkan di HQ - sumber "Tarik Data GDN"
 * (Physical Gold Report, seksyen "GDN Not Yet Received / Not Weighed"). Query LIVE terus ke
 * 'jemisys' (TblTradeInDetail/TblTradeIn, tiada mirror tempatan).
 *
 * MEKANISME DISAHKAN via padanan REKOD SEBENAR terhadap skrin JEMiSys "Receive Trade-In Goods"
 * (filter "Not Received") - BUKAN teka drpd nama lajur:
 * - `TblTradeInDetail.Received = 'N'` SAHAJA x cukup (bagi 811 rekod, bukan 653 sebenar) - WAJIB
 *   JOIN `TblTradeIn` (header) & tapis `Status = '0'` juga - 'V' = void/ditolak, 'C' = closed.
 * - TIADA had tarikh SENGAJA (rujuk UsedGoldBalanceProvider - "baki semasa", bukan laporan
 *   berjangka) - disahkan sesi ni jumlah keseluruhan (tanpa had tarikh) = jumlah dlm julat
 *   semasa jua (tiada backlog lama tersembunyi di luar julat).
 */
class UsedGoldTransitProvider
{
    /**
     * JEMiSys ClassCode (gold type) => kod ketulenan m9os (App\Models\PhysicalGoldPurity).
     * 916/916B/916PTG digabung ke '916' (semua varian purity SAMA 916, cuma bentuk fizikal
     * berbeza - cth. PTG = potongan/scrap - atas arahan eksplisit pengguna). PAUN (syiling emas
     * paun/sovereign) turut digabung ke '916' - konvensyen Malaysia syiling paun purity 916,
     * BUKAN karat berasingan (andaian ni: sahkan dgn staf accounting kalau ada paun purity lain).
     */
    protected const CLASS_CODE_TO_PURITY = [
        '916' => '916',
        '916B' => '916',
        '916PTG' => '916',
        'PAUN' => '916',
        '9999' => '9999',
        '999' => '999',
        '950' => '950',
        '835' => '835',
        '750' => '750',
        '585' => '585',
        '375' => '375',
    ];

    /**
     * Null bermaksud sambungan live 'jemisys' gagal/timeout - BUKAN sifar (jgn papar 0g palsu).
     * Cache pendek (5 minit) - elak sambungan live berulang kalau butang "Tarik Data GDN" ditekan
     * beberapa kali semasa isi borang, tapi kekal segar dlm tempoh munasabah (sama semangat
     * UsedGoldBalanceProvider - TTL pendek, bukan rememberForever).
     *
     * @return array<string, float>|null kod ketulenan m9os => jumlah berat (g)
     */
    public static function pendingByPurity(): ?array
    {
        $byClassCode = Cache::remember(
            'used_gold_transit_pending_by_classcode',
            now()->addMinutes(5),
            fn () => static::fetchByClassCode(),
        );

        if ($byClassCode === null) {
            return null;
        }

        $result = [];

        foreach ($byClassCode as $classCode => $weight) {
            $purityCode = self::CLASS_CODE_TO_PURITY[$classCode] ?? null;

            if ($purityCode === null) {
                continue;
            }

            $result[$purityCode] = ($result[$purityCode] ?? 0.0) + $weight;
        }

        return $result;
    }

    /** @return array<string, float>|null Jemisys ClassCode (tertrim) => jumlah GoldWeight */
    protected static function fetchByClassCode(): ?array
    {
        try {
            $rows = DB::connection('jemisys')->table('TblTradeInDetail as D')
                ->join('TblTradeIn as H', function ($join) {
                    $join->on('H.RefNo', '=', 'D.RefNo')->on('H.StoreCode', '=', 'D.StoreCode');
                })
                ->where('D.Received', 'N')
                ->where('H.Status', '0')
                ->selectRaw('D.ClassCode, SUM(D.GoldWeight) as total_weight')
                ->groupBy('D.ClassCode')
                ->get();

            return $rows->mapWithKeys(fn ($r) => [trim((string) $r->ClassCode) => (float) $r->total_weight])->all();
        } catch (\Throwable $e) {
            Log::warning('UsedGoldTransitProvider: gagal fetch baki GDN pending - '.$e->getMessage());

            return null;
        }
    }
}
