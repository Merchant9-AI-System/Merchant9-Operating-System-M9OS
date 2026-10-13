<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Had masa query di sisi SQL Server (bukan PHP sahaja) - tanpa ni, query tak berindeks yg
 * tamat masa di PHP boleh terus berjalan di server JEMiSys/FINM9 (production, ada POS)
 * dan menghalang yg lain. Dipulihkan ke 0 (tiada had) selepas selesai supaya tak
 * menjejaskan kod lain yg kongsi sambungan sama (cth. job backfill panjang).
 *
 * HEAVY_SECONDS = pemalar timeout DIKONGSI utk query lebih berat (agregat merentas jadual
 * besar tanpa index tarikh sesuai, cth. GoldRateByHolderHist 520K baris heap) - sebelum ni
 * GiftTierAnalytics/MemberAnalytics/MemberDormancyAnalytics/GoldRateAnalytics
 * masing2 declare `public const TIMEOUT_SECONDS = 60;` sendiri (4 salinan serupa, bukan
 * dikongsi) - rujuk AI_AGENTS_PLAN.md item "Ketahanan query".
 *
 * run() kini cuba SEKALI SEMULA (retry(2,...) = percubaan asal + 1 ulang) bila QueryException
 * (timeout/sambungan terputus - mesin dev ni kekurangan RAM, query biasa ~1s boleh >60s bila
 * cache SQL Server sejuk, tapi selalunya pulih dlm percubaan kedua). TIDAK retry pengecualian
 * lain (cth. InvalidArgumentException drpd parameter salah - retry takkan baiki ralat logik).
 */
class SqlServerQueryTimeout
{
    public const DEFAULT_SECONDS = 30;

    public const HEAVY_SECONDS = 60;

    public const RETRY_TIMES = 2;

    public const RETRY_SLEEP_MS = 500;

    public static function run(string $connection, Closure $callback, int $seconds = self::DEFAULT_SECONDS): mixed
    {
        return retry(self::RETRY_TIMES, function () use ($connection, $callback, $seconds) {
            $pdo = DB::connection($connection)->getPdo();
            $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $seconds);

            try {
                return $callback();
            } finally {
                $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 0);
            }
        }, self::RETRY_SLEEP_MS, fn (\Throwable $e) => $e instanceof QueryException);
    }
}
