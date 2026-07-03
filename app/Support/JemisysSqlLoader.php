<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ganti data dlm jemisys.db drpd fail .sql (dump DROP/CREATE/INSERT spt yg dihasilkan
 * drpd export JEMiSys .xlsx). Backup automatik dibuat sebelum load; kalau import gagal
 * separuh jalan, jemisys.db dikembalikan drpd backup supaya tak tinggal keadaan corrupt.
 *
 * Fail dibaca baris demi baris (bukan file_get_contents() seluruh fail) sbb export
 * sebenar boleh cecah ratusan MB - GB, dan memuatkan semuanya dlm memori boleh habiskan
 * memory_limit PHP & gagalkan proses. Memori terhad kpd saiz satu statement (satu
 * CREATE TABLE atau satu batch INSERT), bukan saiz keseluruhan fail.
 */
class JemisysSqlLoader
{
    /** @return string Laluan penuh fail backup yg dibuat sebelum load. */
    public static function load(string $sqlFilePath): string
    {
        $dbPath = config('database.connections.jemisys.database');

        if (! $dbPath || ! file_exists($dbPath)) {
            throw new RuntimeException("Fail jemisys.db tak dijumpai di: {$dbPath}");
        }

        if (! file_exists($sqlFilePath) || filesize($sqlFilePath) === 0) {
            throw new RuntimeException('Fail SQL kosong atau tak boleh dibaca.');
        }

        $backupPath = dirname($dbPath).DIRECTORY_SEPARATOR.'jemisys.db.pre_load_'.now()->format('Ymd_His').'.bak';

        DB::purge('jemisys');

        if (! copy($dbPath, $backupPath)) {
            throw new RuntimeException('Gagal buat backup sebelum load - proses dibatalkan.');
        }

        $connection = DB::connection('jemisys');

        try {
            $connection->beginTransaction();

            self::streamStatements($sqlFilePath, function (string $statement) use ($connection) {
                $connection->unprepared($statement);
            });

            self::createInventoryIndexes($connection);

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            DB::purge('jemisys');
            copy($backupPath, $dbPath);

            throw new RuntimeException('Import gagal, jemisys.db dikembalikan ke keadaan asal: '.$e->getMessage(), previous: $e);
        }

        return $backupPath;
    }

    /**
     * TblInventory (ratusan ribu baris) tak pernah ada index langsung dlm dump asal -
     * setiap widget/kalkulator dashboard buat full table scan (4+ saat setiap satu,
     * pernah sampai habiskan memory_limit bila semua widget jalan serentak). Sbb ni
     * connection 'jemisys' cuma dibaca (bukan ditulis oleh app), tiada kos write utk
     * index tambahan - hanya kos storan & sikit masa import, jadi selamat index banyak
     * sekali gus.
     *
     * Kebanyakan index dibina sbg "covering index" (lajur GROUP BY dulu, ikut lajur
     * yg diagregat spt SalesDate/QtyOnHand/TotalCost) - disahkan scr langsung guna
     * EXPLAIN QUERY PLAN, index biasa (VendorCode, InternalCode) saja cuma naikkan
     * kelajuan query "GROUP BY InternalCode" drpd 3.6s ke 2.9s (SQLite kena patah balik
     * ke jadual asal utk baca SalesDate/QtyOnHand bagi setiap baris), tapi covering
     * index (InternalCode, VendorCode, SalesDate, QtyOnHand) naikkan ke 0.4s (~9x lagi
     * laju) sbb SQLite boleh jawab terus drpd index tanpa sentuh jadual asal langsung.
     * Lajur & kombinasi dipilih drpd audit sebenar setiap query dlm
     * app/Support/*Calculator.php, app/Filament/Widgets/*.php, & InventoryPiecesTable.
     */
    private static function createInventoryIndexes(Connection $connection): void
    {
        $tableExists = $connection->selectOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'TblInventory'"
        );

        if (! $tableExists) {
            return;
        }

        $indexes = [
            // Covering index bagi setiap bentuk GROUP BY sebenar dlm codebase - lajur
            // GROUP BY dulu (elak temp b-tree sort), diikuti lajur yg diagregat.
            'idx_inv_internalcode_covering' => ['InternalCode', 'VendorCode', 'SalesDate', 'QtyOnHand'],
            'idx_inv_vendorcode_internalcode_covering' => ['VendorCode', 'InternalCode', 'SalesDate', 'QtyOnHand'],
            'idx_inv_internalcode_storecode_covering' => ['InternalCode', 'StoreCode', 'VendorCode', 'SalesDate', 'QtyOnHand'],
            'idx_inv_storecode_categorycode_covering' => ['StoreCode', 'CategoryCode', 'VendorCode', 'SalesDate', 'QtyOnHand'],
            'idx_inv_categorycode_storecode_covering' => ['CategoryCode', 'StoreCode', 'VendorCode', 'SalesDate', 'QtyOnHand', 'GoldWeight'],
            'idx_inv_categorycode_storecode_jewelsize_covering' => ['CategoryCode', 'StoreCode', 'JewelSize', 'VendorCode', 'SalesDate', 'QtyOnHand'],
            'idx_inv_categorycode_covering' => ['CategoryCode', 'VendorCode', 'SalesDate', 'QtyOnHand'],
            'idx_inv_storecode_onhand_covering' => ['StoreCode', 'QtyOnHand', 'VendorCode', 'GoldWeight'],
            'idx_inv_vendorcode_group_covering' => ['VendorCode', 'SalesDate', 'QtyOnHand', 'TotalCost'],
            'idx_inv_vendorcode_salesamount_covering' => ['VendorCode', 'SalesDate', 'SalesAmount', 'TotalCost'],
            'idx_inv_onhand_vendorcode_purchdate_covering' => ['QtyOnHand', 'VendorCode', 'PurchDate', 'TotalCost', 'GoldWeight'],
            'idx_inv_vendorcode_salesdate' => ['VendorCode', 'SalesDate'],

            // Filter/sort table InventoryPieces - base query resource ni sentiasa
            // ->onHand()->realVendor(), jadi index diketuai (QtyOnHand, VendorCode).
            'idx_inv_onhand_vendorcode_storecode' => ['QtyOnHand', 'VendorCode', 'StoreCode'],
            'idx_inv_onhand_vendorcode_categorycode' => ['QtyOnHand', 'VendorCode', 'CategoryCode'],
            'idx_inv_onhand_vendorcode_classcode' => ['QtyOnHand', 'VendorCode', 'ClassCode'],
            'idx_inv_onhand_vendorcode_totalcost' => ['QtyOnHand', 'VendorCode', 'TotalCost'],
            'idx_inv_onhand_vendorcode_goldweight' => ['QtyOnHand', 'VendorCode', 'GoldWeight'],
            'idx_inv_onhand_classcode' => ['QtyOnHand', 'ClassCode'],

            // Index tunggal sbg fallback bagi query ad-hoc/lain yg xde dlm senarai atas.
            'idx_inv_vendorcode' => ['VendorCode'],
            'idx_inv_storecode' => ['StoreCode'],
            'idx_inv_categorycode' => ['CategoryCode'],
            'idx_inv_classcode' => ['ClassCode'],
            'idx_inv_purchdate' => ['PurchDate'],
        ];

        foreach ($indexes as $name => $columns) {
            $columnList = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
            $connection->statement("CREATE INDEX IF NOT EXISTS \"{$name}\" ON \"TblInventory\" ({$columnList})");
        }
    }

    /**
     * Baca fail .sql baris demi baris & panggil $execute() sekali bagi setiap statement
     * lengkap. Sempadan statement dikesan bila baris (selepas trim hujung) berakhir dgn
     * ';' - selamat utk format dump ni sbb setiap baris cuma satu lajur (CREATE TABLE)
     * atau satu baris/tuple INSERT, dan ';' penutup sentiasa jadi aksara terakhir baris.
     */
    private static function streamStatements(string $path, callable $execute): void
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Tak boleh buka fail: {$path}");
        }

        try {
            $buffer = '';

            while (($line = fgets($handle)) !== false) {
                $buffer .= $line;

                if (! str_ends_with(rtrim($line), ';')) {
                    continue;
                }

                $statement = trim($buffer);
                $buffer = '';

                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }

                // MySQL-only, tak wujud dlm SQLite - sambungan 'jemisys' dah set
                // foreign_key_constraints => false, jadi selamat dibuang.
                if (preg_match('/^SET FOREIGN_KEY_CHECKS\s*=\s*\d+;$/i', $statement)) {
                    continue;
                }

                $execute($statement);
            }

            $leftover = trim($buffer);

            if ($leftover !== '') {
                throw new RuntimeException('Fail SQL tamat dgn statement tak lengkap (xde \';\' penutup): '.substr($leftover, 0, 200));
            }
        } finally {
            fclose($handle);
        }
    }
}
