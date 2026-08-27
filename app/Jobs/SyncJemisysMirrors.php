<?php

namespace App\Jobs;

use App\Models\InventoryMirror;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use App\Models\User;
use App\Support\StockoutReorderMaterializer;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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
 *
 * PENGERASAN (disahkan production sebenar 2026-08-27): sambungan Tailscale/SQL Server boleh
 * putus SENYAP tengah cursor() satu StoreCode - pulangkan set kosong/separa TANPA throw (bukan
 * ralat PHP/PDO biasa), StoreCode terakhir dlm urutan (cth. WEB/WM) jadi kosong/separa walhal
 * job log "selesai" tanpa ralat. syncStoreBatch() kini bandingkan kiraan disegerak vs kiraan
 * SUMBER lepas setiap commit - kalau jauh kurang (>2% pincang), throw RuntimeException SENGAJA
 * supaya job (mod PENUH mahupun resume) GAGAL SECARA JELAS (Log::error + notification loceng),
 * BUKAN senyap "berjaya" dgn data x lengkap.
 *
 * $resume=true (rujuk JemisysConnectionStatus butang "Resume Data Tidak Lengkap") - guna lepas
 * sync biasa gagal/disyaki x lengkap. TIADA truncate() jadual penuh - bandingkan kiraan
 * per-StoreCode (SUMBER vs cermin, normalize huruf besar/kecil+ruang - rujuk
 * findStoresNeedingResync()) DULU, hanya store dgn jurang >2% (ambang sama dgn PENGERASAN di
 * atas - store SIHAT padan 0% dlm ujian sebenar, store PECAH 76-100% pincang, banyak margin)
 * dipadam+disegerak SEMULA. Store yg dah lengkap TIDAK disentuh.
 */
class SyncJemisysMirrors implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY_SYNCING = 'jemisys_mirrors_syncing';

    /** 30 minit - jauh lebih besar drpd retry_after=90s queue connection 'database' lalai. */
    public $timeout = 1800;

    /** Gagal separuh jalan sepatutnya di-trigger semula bersih via butang, bukan auto-retry. */
    public $tries = 1;

    public function __construct(public bool $resume = false)
    {
        //
    }

    public function handle(): void
    {
        // Selamatkan job ni drpd OOM (disahkan production - StockoutReorderMaterializer::
        // materialize() exhaust memory_limit 512M lepas ~490K baris TblInventory disegerak dlm
        // PROSES PHP YG SAMA sebelum sampai ke situ). Job background CLI (queue worker), bukan
        // permintaan web - selamat naikkan sementara utk proses ni sahaja, bukan ubah php.ini global.
        //
        // try/catch WAJIB di sini - disahkan production PHP-FPM kunci memory_limit via
        // php_admin_value (siling 512M tegar, tak boleh naik langsung via ini_set() runtime).
        // ini_set() gagal dlm keadaan tu bukan sekadar return false senyap - Laravel tukar
        // amaran PHP jadi ErrorException yg akan crash job ni SEBELUM try/catch utama bawah
        // (di luar skop try tu) kalau x ditangkap di sini. Job proceed ikut memory_limit
        // SEDIA ADA server (kekal berisiko OOM kalau siling server < keperluan sebenar - rujuk
        // nota production, perlu dinaikkan di tahap php.ini/FPM pool, bukan runtime).
        try {
            ini_set('memory_limit', '1536M');
        } catch (Throwable) {
        }

        // Nilai cache = masa mula (bukan sekadar `true`) - UI guna ni utk papar timer berjalan.
        Cache::put(self::CACHE_KEY_SYNCING, now(), now()->addHours(1));

        $start = microtime(true);

        try {
            $this->syncSmallTable('TblCategory', (new Category)->getTable());
            $this->syncSmallTable('TblVendor', (new Vendor)->getTable());
            $this->syncSmallTable('TblStore', (new Store)->getTable());

            $total = $this->resume ? $this->resumeInventory() : $this->syncInventory();

            // StockoutReorder baca terus drpd jadual ni (bukan agregat live) - rujuk nota
            // App\Support\StockoutReorderMaterializer/App\Filament\Pages\StockoutReorder.
            $stockoutCandidateCount = StockoutReorderMaterializer::materialize();

            // Semua kalkulator berat guna Cache::rememberForever() (bukan TTL 3600s) - staleness
            // kekal terikat pada bila sync/warm terakhir berjaya, BUKAN tempoh tamat rawak yg
            // boleh terkena permintaan pengguna sebenar (live recompute 40-50 saat --> 504).
            // Ini bermakna Cache::flush() DI SINI ialah SATU-SATUNYA cara data jadi stale/refresh -
            // wajib disusuli warm-dashboard-cache serta-merta (bukan pilihan) atau semua page
            // akan cuba recompute LIVE dlm permintaan pengguna seterusnya lepas flush ni.
            Cache::flush();
            Artisan::call('app:warm-dashboard-cache');
            Artisan::call('app:warm-jemisys-diagnostics');

            $ms = round((microtime(true) - $start) * 1000);
            $mode = $this->resume ? 'resume' : 'penuh';
            Log::info("SyncJemisysMirrors: selesai ({$mode}) - {$total} baris TblInventory + Category/Vendor/Store, ".
                "{$stockoutCandidateCount} calon StockoutReorder ({$ms}ms).");
        } catch (Throwable $e) {
            Log::error('SyncJemisysMirrors gagal: '.$e->getMessage());

            // Job ni juga dijadualkan scheduler (rujuk routes/console.php) - TIADA sesiapa
            // tengok Forge log secara aktif bila auto-jalan (bukan klik butang manual spt
            // biasa), jadi lantunkan juga sbg notifikasi Filament (bell icon) ke super_admin
            // supaya kegagalan (cth. laptop sumber tertutup/Tailscale down) tetap disedari.
            $userAdmin = User::role(config('filament-shield.super_admin.name'))->first();

            if ($userAdmin) {
                Notification::make()
                    ->danger()
                    ->title('SyncJemisysMirrors gagal')
                    ->body($e->getMessage())
                    ->sendToDatabase($userAdmin);
            }

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
        $ctx = $this->prepareInventorySyncContext();

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
        //
        // getSourceStoreCounts() (bukan setakat distinct()->pluck('StoreCode')) - expectedCount
        // setiap store diperlukan utk semakan kewarasan syncStoreBatch() (rujuk dokblok kaedah
        // tsb) - SATU query GROUP BY tambahan drpd sini, bukan N query berasingan per-store.
        $storeCounts = $this->getSourceStoreCounts();

        $total = 0;

        foreach ($storeCounts as $storeCode => $expectedCount) {
            $total += $this->syncStoreBatch($storeCode, $ctx, $total, $expectedCount);
        }

        return $total;
    }

    /**
     * Mod resume (rujuk dokblok kelas) - TIADA truncate() jadual penuh. Bandingkan kiraan
     * per-StoreCode (SUMBER vs cermin) DULU, hanya padam+segerak semula store dgn jurang >2%.
     */
    private function resumeInventory(): int
    {
        $needsResync = $this->findStoresNeedingResync();

        if ($needsResync->isEmpty()) {
            Log::info('SyncJemisysMirrors: mod resume - semua store dah lengkap, tiada apa disegerak semula.');

            return InventoryMirror::count();
        }

        $ctx = $this->prepareInventorySyncContext();

        foreach ($needsResync as $store) {
            Log::info("SyncJemisysMirrors: resume {$store['storeCode']} - sumber={$store['sourceCount']} cermin={$store['mirrorCount']} (jurang {$store['diffPercent']}%), padam & segerak semula...");

            // whereRaw(LOWER(TRIM(...))) - padan padding/case StoreCode antara jadual (rujuk
            // dokblok kelas & findStoresNeedingResync()), BUKAN ->where('StoreCode', $code)
            // exact - baris sedia ada utk store ni boleh terpadam separuh drpd punca sync gagal.
            InventoryMirror::whereRaw('LOWER(TRIM(StoreCode)) = ?', [mb_strtolower(trim($store['storeCode']))])->delete();

            $this->syncStoreBatch($store['storeCode'], $ctx, 0, $store['sourceCount']);
        }

        return InventoryMirror::count();
    }

    /**
     * Bandingkan kiraan per-StoreCode (SUMBER jemisys vs cermin tempatan), normalize
     * huruf besar/kecil+ruang (rujuk dokblok kelas - StoreCode kadangkala wujud dlm case
     * berbeza antara jadual, cth. "SECURITY" vs "security"). Ambang 2% - store SIHAT (disahkan
     * ujian sebenar) padan 0% tepat, store PECAH 76-100% pincang - banyak margin drpd naik
     * turun jualan normal harian.
     *
     * @return Collection<int, array{storeCode: string, sourceCount: int, mirrorCount: int, diffPercent: float}>
     */
    private function findStoresNeedingResync(): Collection
    {
        $source = $this->getSourceStoreCounts();

        $mirror = InventoryMirror::query()
            ->selectRaw('LOWER(TRIM(StoreCode)) as sc, COUNT(*) as c')
            ->groupByRaw('LOWER(TRIM(StoreCode))')
            ->toBase()
            ->get()
            ->pluck('c', 'sc');

        return $source->map(function (int $sourceCount, string $storeCode) use ($mirror) {
            $normalized = mb_strtolower(trim($storeCode));
            $mirrorCount = (int) ($mirror[$normalized] ?? 0);
            $diffPercent = $sourceCount > 0 ? round((($sourceCount - $mirrorCount) / $sourceCount) * 100, 1) : 0.0;

            return [
                'storeCode' => $storeCode,
                'sourceCount' => $sourceCount,
                'mirrorCount' => $mirrorCount,
                'diffPercent' => $diffPercent,
            ];
        })
            ->filter(fn (array $s) => $s['diffPercent'] > 2.0)
            ->values();
    }

    /**
     * Kiraan baris per-StoreCode (RAW, bukan normalize) drpd sumber jemisys - SATU query GROUP
     * BY dikongsi oleh syncInventory() (expectedCount semakan kewarasan) & findStoresNeedingResync()
     * (bandingan resume), elak query berulang.
     *
     * @return Collection<string, int> StoreCode (raw) => kiraan baris
     */
    private function getSourceStoreCounts(): Collection
    {
        return DB::connection('jemisys')->table('TblInventory')
            ->selectRaw('StoreCode, COUNT(*) as c')
            ->groupBy('StoreCode')
            ->get()
            ->pluck('c', 'StoreCode')
            ->map(fn ($c) => (int) $c);
    }

    /**
     * Sedia konteks dikongsi (chunk size/nickname sedia ada) SEKALI - dipanggil oleh
     * syncInventory() (mod penuh) & resumeInventory() (mod resume), elak duplikasi.
     *
     * @return array{chunkSize: int, rowsPerTransaction: int, knownMerchantData: array<string, array<string, mixed>>}
     */
    private function prepareInventorySyncContext(): array
    {
        // TblInventory ada 146 lajur - SQLite hadkan ~999 parameter berikat setiap statement,
        // jadi saiz batch kena dikira ikut bilangan lajur, bukan angka tetap (angka tetap yg
        // selamat utk MySQL/Postgres akan pecah kat SQLite).
        $columnCount = count(Schema::getColumnListing('jemisys_inventory_mirror'));
        $maxParams = DB::connection()->getDriverName() === 'sqlite' ? 900 : 20000;
        $chunkSize = max(1, intdiv($maxParams, $columnCount));

        // nickname/image_url/merchant_synced_at BUKAN drpd TblInventory - diisi BERASINGAN
        // oleh App\Jobs\SyncMerchantNicknamesAndImages (scrape merchant9.com, rujuk dokblok job
        // tsb - SENGAJA diasingkan drpd sync ni sbb HTTP fetch amat perlahan, ~700ms/kod SEJUK).
        // Mod penuh truncate() SEMUA data termasuk lajur ni - tanpa tangkap dulu, setiap
        // resync JEMiSys akan null-kan balik apa yg job backfill tu dah berjaya isi, memaksa job
        // tu scrape SEMULA semua ~27K InternalCode dari kosong setiap kali sync ni jalan. Tangkap
        // peta code->{nickname,image_url,merchant_synced_at} SEDIA ADA sblm truncate (bacaan DB
        // SAHAJA, TIADA HTTP - x reintroduce isu timeout), guna balik semasa insert bawah -
        // hanya design BAHARU (blm pernah disegerak job backfill) akan null lepas resync ni.
        // toBase()->get() (bukan ->get() Eloquent terus) - disahkan production 169K+ drpd 490K
        // baris dah ada merchant_synced_at (lepas backfill job jalan meluas), hydrate SEMUA tu
        // sbg Model Eloquent PENUH (attributes/original/casts/relations setiap satu) exhaust
        // memory_limit 512M SEBELUM loop insert bawah pun mula - punca OOM disahkan production.
        // toBase() pulangkan stdClass mentah drpd Query Builder, jauh lebih ringan drpd Model.
        $knownMerchantData = InventoryMirror::query()
            ->whereNotNull('merchant_synced_at')
            ->select(['InternalCode', 'nickname', 'image_url', 'merchant_synced_at'])
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [trim((string) $row->InternalCode) => [
                'nickname' => $row->nickname,
                'image_url' => $row->image_url,
                'merchant_synced_at' => $row->merchant_synced_at,
            ]])
            ->all();

        return [
            'chunkSize' => $chunkSize,
            // Commit berkala (bukan satu transaction raksasa merentasi kesemua 481K baris) -
            // elak satu transaction panjang sekat proses lain (cth. SQLite hanya benarkan SATU
            // penulis serentak), tapi commit setiap statement individu pun terlalu perlahan
            // (fsync berulang) - jadi commit setiap ~5000 baris sbg titik tengah munasabah.
            'rowsPerTransaction' => 5000,
            'knownMerchantData' => $knownMerchantData,
        ];
    }

    /**
     * Segerak SATU StoreCode - dikongsi oleh mod penuh (syncInventory()) & mod resume
     * (resumeInventory()). $runningTotal HANYA utk log ("jumlah setakat ini") - baris
     * dipulangkan ialah kiraan store INI sahaja.
     *
     * $expectedCount - semakan kewarasan lepas commit (rujuk PENGERASAN bawah) - kiraan SUMBER
     * bagi StoreCode ni (dari getSourceStoreCounts()), diambil SEBELUM cursor() mula baca.
     *
     * PENGERASAN (disahkan production 2026-08-27): sambungan Tailscale/SQL Server boleh putus
     * SENYAP tengah cursor() - pulangkan set kosong/separa TANPA throw (bukan ralat PHP/PDO
     * biasa), jadi kod asal ini akan log "selesai" & terus macam BERJAYA walau StoreCode
     * (cth. WM/WEB) sebenarnya kosong/separa. Semakan bawah bandingkan $storeTotal vs
     * $expectedCount lepas commit - kalau jauh kurang (>2% pincang, ambang sama findStoresNeedingResync()),
     * throw RuntimeException SENGAJA supaya job ni GAGAL SECARA JELAS (masuk laluan catch luar
     * handle() - Log::error + notification loceng ke super_admin), BUKAN senyap "berjaya" dgn
     * cawangan yg sebenarnya tak lengkap.
     *
     * @param  array{chunkSize: int, rowsPerTransaction: int, knownMerchantData: array<string, array<string, mixed>>}  $ctx
     */
    private function syncStoreBatch(string $storeCode, array $ctx, int $runningTotal, int $expectedCount): int
    {
        $storeTotal = 0;
        $rowsSinceCommit = 0;

        DB::beginTransaction();

        try {
            $buffer = [];

            DB::connection('jemisys')
                ->table('TblInventory')
                ->where('StoreCode', $storeCode)
                ->cursor()
                ->each(function ($row) use (&$buffer, &$storeTotal, &$rowsSinceCommit, $ctx) {
                    // trim() PHP tulen (bukan SQL) dipanggil 490K kali di sini - kos µs
                    // setiap satu, BUKAN isu yg sama dgn WHERE TRIM(...)=? diulang dlm SQL
                    // (rujuk dokblok SyncMerchantNicknamesAndImages) - ini cuma array lookup
                    // dlm memori, bukan query DB.
                    $merchantData = $ctx['knownMerchantData'][trim((string) $row->InternalCode)] ?? [
                        'nickname' => null,
                        'image_url' => null,
                        'merchant_synced_at' => null,
                    ];

                    $buffer[] = (array) $row + ['synced_at' => now()] + $merchantData;

                    if (count($buffer) >= $ctx['chunkSize']) {
                        InventoryMirror::insert($buffer);
                        $storeTotal += count($buffer);
                        $rowsSinceCommit += count($buffer);
                        $buffer = [];

                        if ($rowsSinceCommit >= $ctx['rowsPerTransaction']) {
                            DB::commit();
                            DB::beginTransaction();
                            $rowsSinceCommit = 0;
                        }
                    }
                });

            if ($buffer !== []) {
                InventoryMirror::insert($buffer);
                $storeTotal += count($buffer);
            }

            DB::commit();

            Log::info("SyncJemisysMirrors: batch TblInventory {$storeCode} selesai ({$storeTotal} baris store ni, ".
                ($runningTotal + $storeTotal).' baris jumlah setakat ini).');

            // Rujuk dokblok PENGERASAN di atas - throw di sini SENGAJA ditangkap oleh catch
            // Throwable bawah (rollback aman, transactionLevel dah 0 lepas commit berjaya di
            // atas jadi tiada apa nak rollback - padan nota "Jaga-jaga..." sedia ada), kemudian
            // dilontar semula ke handle() supaya job GAGAL SECARA JELAS.
            if ($expectedCount > 0 && $storeTotal < $expectedCount * 0.98) {
                $shortPercent = round((($expectedCount - $storeTotal) / $expectedCount) * 100, 1);

                throw new RuntimeException("Cawangan {$storeCode} nampak x lengkap - jangka {$expectedCount} baris, dapat {$storeTotal} sahaja ({$shortPercent}% pincang). Kemungkinan sambungan Tailscale/VPN terputus tengah proses.");
            }
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

        return $storeTotal;
    }
}
