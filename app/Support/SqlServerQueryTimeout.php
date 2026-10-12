<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Had masa query di sisi SQL Server (bukan PHP sahaja) - tanpa ni, query tak berindeks yg
 * tamat masa di PHP boleh terus berjalan di server JEMiSys/FINM9 (production, ada POS)
 * dan menghalang yg lain. Dipulihkan ke 0 (tiada had) selepas selesai supaya tak
 * menjejaskan kod lain yg kongsi sambungan sama (cth. job backfill panjang).
 */
class SqlServerQueryTimeout
{
    public const DEFAULT_SECONDS = 30;

    public static function run(string $connection, Closure $callback, int $seconds = self::DEFAULT_SECONDS): mixed
    {
        $pdo = DB::connection($connection)->getPdo();
        $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $seconds);

        try {
            return $callback();
        } finally {
            $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 0);
        }
    }
}
