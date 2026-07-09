<?php

namespace App\Jobs;

use App\Models\InventoryMirror;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Segerak TblCategory/TblVendor/TblStore/TblInventory (jemisys, live SQL Server via Tailscale)
 * -> cermin tempatan (DB lalai). Category/Vendor/Store kecil (<250 baris jumlah) - truncate +
 * insert terus tanpa batching. TblInventory (481K baris) kekal batch per StoreCode spt asal.
 *
 * Category/Vendor/Store disegerak DULU (supaya relationship InventoryPiece->vendor()/
 * ->category()/->store() kekal pd SAMBUNGAN SAMA dgn InventoryPiece - elak ralat
 * cross-connection "Base table or view not found: TblVendor" bila lajur relation
 * di-searchable()/sortable() dlm Filament, cth. StockoutReorder/InventoryPiecesTable).
 *
 * Dijalankan sebagai queued job (BUKAN sync dlm request) sebab copy 481K baris melalui VPN
 * Tailscale ambil masa lama - kalau run dlm request butang, ia akan kena 30s timeout/504 yg
 * sama spt yg kita dah selesaikan sepanjang sesi ni.
 */
class SyncJemisysMirrors implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY_SYNCING = 'jemisys_mirrors_syncing';

    /** 30 minit - jauh lebih besar drpd retry_after=90s queue connection 'database' lalai. */
    public $timeout = 1800;

    /** Gagal separuh jalan sepatutnya di-trigger semula bersih via butang, bukan auto-retry. */
    public $tries = 1;

    public function handle(): void
    {
        // Nilai cache = masa mula (bukan sekadar `true`) - UI guna ni utk papar timer berjalan.
        Cache::put(self::CACHE_KEY_SYNCING, now(), now()->addHours(1));

        $start = microtime(true);

        try {
            $this->syncSmallTable('TblCategory', (new Category)->getTable());
            $this->syncSmallTable('TblVendor', (new Vendor)->getTable());
            $this->syncSmallTable('TblStore', (new Store)->getTable());

            $total = $this->syncInventory();

            $ms = round((microtime(true) - $start) * 1000);
            Log::info("SyncJemisysMirrors: selesai - {$total} baris TblInventory + Category/Vendor/Store ({$ms}ms).");
        } catch (Throwable $e) {
            Log::error('SyncJemisysMirrors gagal: '.$e->getMessage());

            throw $e;
        } finally {
            Cache::forget(self::CACHE_KEY_SYNCING);
        }
    }

    /** Jadual kecil (<250 baris) - truncate + satu insert terus, tiada batching diperlukan. */
    private function syncSmallTable(string $sourceTable, string $localTable): void
    {
        $rows = DB::connection('jemisys')->table($sourceTable)->get()
            ->map(fn ($row) => (array) $row + ['synced_at' => now()])
            ->all();

        DB::table($localTable)->truncate();

        if ($rows !== []) {
            DB::table($localTable)->insert($rows);
        }

        Log::info('SyncJemisysMirrors: '.$sourceTable.' -> '.$localTable.' ('.count($rows).' baris).');
    }

    /** TblInventory (481K baris) - batch per StoreCode, commit berkala. Rujuk nota asal di bawah. */
    private function syncInventory(): int
    {
        $total = 0;

        // TblInventory ada 146 lajur - SQLite hadkan ~999 parameter berikat setiap statement,
        // jadi saiz batch kena dikira ikut bilangan lajur, bukan angka tetap (angka tetap yg
        // selamat utk MySQL/Postgres akan pecah kat SQLite).
        $columnCount = count(Schema::getColumnListing('jemisys_inventory_mirror'));
        $maxParams = DB::connection()->getDriverName() === 'sqlite' ? 900 : 20000;
        $chunkSize = max(1, intdiv($maxParams, $columnCount));

        // Commit berkala (bukan satu transaction raksasa merentasi kesemua 481K baris) - elak
        // satu transaction panjang sekat proses lain (cth. SQLite hanya benarkan SATU penulis
        // serentak), tapi commit setiap statement individu pun terlalu perlahan (fsync
        // berulang) - jadi commit setiap ~5000 baris sbg titik tengah yg munasabah.
        $rowsPerTransaction = 5000;
        $rowsSinceCommit = 0;

        // truncate() KENA di luar transaction - TRUNCATE TABLE buat implicit commit dlm MySQL
        // (turut tamatkan transaction terdahulu secara senyap), jadi kalau dipanggil SELEPAS
        // beginTransaction(), Laravel akan fikir transaction masih terbuka (transactionLevel
        // tetap 1) sedangkan PDO dah auto-commit - punca sebenar "There is no active
        // transaction" pada commit() pertama lepas ni.
        InventoryMirror::truncate();

        // Baca ikut BATCH per StoreCode (bukan satu query merentasi kesemua 481K baris) -
        // StoreCode ialah lajur utama PK_TblInventory (clustered), jadi WHERE StoreCode = ?
        // boleh seek terus (murah) tanpa perlukan index tambahan. 9 store bermakna 9 query
        // lebih kecil (2,926 - 112,419 baris setiap satu) drpd 1 query gergasi 481K baris -
        // kurangkan tekanan buffer pool SQL Server yg punca ralat "insufficient memory"
        // sebelum ni.
        $storeCodes = DB::connection('jemisys')->table('TblInventory')->distinct()->pluck('StoreCode');

        foreach ($storeCodes as $storeCode) {
            DB::beginTransaction();

            try {
                $buffer = [];

                DB::connection('jemisys')
                    ->table('TblInventory')
                    ->where('StoreCode', $storeCode)
                    ->cursor()
                    ->each(function ($row) use (&$buffer, &$total, &$rowsSinceCommit, $chunkSize, $rowsPerTransaction) {
                        $buffer[] = (array) $row + ['synced_at' => now()];

                        if (count($buffer) >= $chunkSize) {
                            InventoryMirror::insert($buffer);
                            $total += count($buffer);
                            $rowsSinceCommit += count($buffer);
                            $buffer = [];

                            if ($rowsSinceCommit >= $rowsPerTransaction) {
                                DB::commit();
                                DB::beginTransaction();
                                $rowsSinceCommit = 0;
                            }
                        }
                    });

                if ($buffer !== []) {
                    InventoryMirror::insert($buffer);
                    $total += count($buffer);
                }

                DB::commit();
                $rowsSinceCommit = 0;

                Log::info("SyncJemisysMirrors: batch TblInventory {$storeCode} selesai ({$total} baris jumlah setakat ini).");
            } catch (Throwable $e) {
                // Jaga-jaga sekiranya transaction dah tertutup secara luaran atas sebab lain
                // (cth. proses sync lain berjalan serentak & DB kunci) - rollBack() sendiri pun
                // boleh throw "There is no active transaction" dlm keadaan ni. Telan sahaja
                // supaya ralat ASAL (bukan ralat rollback sekunder) yg log & sampai ke pengguna.
                try {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                } catch (Throwable) {
                    // diabaikan sengaja - lihat nota di atas.
                }

                throw $e;
            }
        }

        return $total;
    }
}
