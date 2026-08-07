<?php

namespace App\Jobs;

use App\Models\InventoryMirror;
use App\Support\MerchantWebsiteSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Isi nickname & image_url jemisys_inventory_mirror drpd merchant9.com - BERASINGAN drpd
 * SyncJemisysMirrors (butang sendiri di JemisysConnectionStatus, TIADA auto-dispatch selepas
 * inventory sync - keputusan explicit pengguna) sebab scrape HTTP (~700ms/kod SEJUK x ~27,777
 * InternalCode unik ≈ berjam-jam) akan blow past $timeout=1800s SyncJemisysMirrors kalau
 * digabung terus dlm loop sync tu (disahkan betul2 terjadi sepanjang sesi ni sblm direvert).
 *
 * SATU fetch/parse sahaja setiap kod (MerchantWebsiteSearch::search()) bagi KEDUA-DUA nickname
 * (field 'name') & image_url (field 'image_url') drpd kad HASIL PERTAMA yg sama - BUKAN panggil
 * ProductImageFetcher berasingan utk imej (tu akan scrape muka yg SAMA dua kali, & pasangkan
 * nickname/imej drpd DUA mekanisme berlainan yg boleh terpisah/x konsisten).
 *
 * InternalCode ialah CHAR fixed-width (rujuk dokblok penuh App\Http\Controllers\
 * BranchDemandEntryController - isu padding sama dgn StoreCode/CategoryCode/JewelSize) - SELECT
 * DISTINCT drpd nilai MENTAH (blm trim) bawah ni tetap dedupe betul (setiap baris fizikal design
 * SAMA ada padding byte-identical), & WHERE UPDATE guna nilai MENTAH tu TERUS (bukan trim())
 * supaya exact match kekal guna index InternalCode sedia ada - kalau WHERE TRIM(InternalCode)=?
 * diulang 27,777 kali, MySQL wrap lajur dlm fungsi, defeat index, force near-full-scan (490K
 * baris) SETIAP satu drpd 27,777 update. TRIM() cuma dipakai (a) sbg SALINAN utk hantar sbg
 * carian ke MerchantWebsiteSearch::search()/log - bukan dlm WHERE UPDATE.
 */
class SyncMerchantNicknamesAndImages implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY_SYNCING = 'merchant_nicknames_syncing';

    /**
     * 12 jam - job ni SENGAJA lama (rujuk dokblok kelas). Run PERTAMA (semua ~27,777 kod sejuk,
     * tiada satu pun ada merchant_synced_at) anggaran ~7 jam (27,777 x [~700ms fetch + 200ms
     * throttle]) - beri banyak ruang lagi drpd anggaran tu. Run SETERUSNYA jauh lebih pantas
     * (hanya kod BAHARU sejak sync terakhir, rujuk carry-forward SyncJemisysMirrors::syncInventory()).
     */
    public $timeout = 43200;

    /** Gagal separuh jalan patut retrigger bersih via butang (merchant_synced_at yg dah siap
     * kekal, cuma yg blm siap diulang) - bukan auto-retry queue. */
    public $tries = 1;

    public function handle(): void
    {
        // TTL jauh > $timeout (spt SyncJemisysMirrors) - kalau x, flag "syncing" boleh tamat
        // sendiri sblm job betul2 selesai, buat UI tersilap papar "tak syncing".
        Cache::put(self::CACHE_KEY_SYNCING, now(), now()->addHours(24));

        $start = microtime(true);
        $processed = 0;
        $matched = 0;

        try {
            // rujuk dokblok kelas - TRIM() TIDAK dipakai di sini (nilai MENTAH dipluck terus).
            $rawCodes = InventoryMirror::query()
                ->whereNotNull('InternalCode')
                ->where('InternalCode', '!=', '')
                ->whereNull('merchant_synced_at')
                ->distinct()
                ->pluck('InternalCode');

            $total = $rawCodes->count();
            Log::info("SyncMerchantNicknamesAndImages: mula - {$total} InternalCode unik belum diisi.");

            foreach ($rawCodes as $rawCode) {
                $searchTerm = trim((string) $rawCode);

                if ($searchTerm === '') {
                    continue;
                }

                $results = MerchantWebsiteSearch::search($searchTerm);
                $first = $results[0] ?? null;

                // WHERE guna $rawCode MENTAH (bukan $searchTerm ditrim!) - rujuk dokblok kelas
                // utk sebab (exact match kekal guna index InternalCode, x wrap dlm TRIM()).
                // merchant_synced_at diisi WALAUPUN $first null - tanda "sudah ditanya" supaya
                // kod yg SAH x wujud di storefront x discrape berulang setiap run akan datang.
                InventoryMirror::where('InternalCode', $rawCode)->update([
                    'nickname' => $first['name'] ?? null,
                    'image_url' => $first['image_url'] ?? null,
                    'merchant_synced_at' => now(),
                ]);

                $processed++;
                $matched += $first !== null ? 1 : 0;

                if ($processed % 250 === 0) {
                    Log::info("SyncMerchantNicknamesAndImages: {$processed}/{$total} kod diproses ({$matched} padan setakat ini).");
                }

                // Elak bebankan merchant9.com (storefront awam, BUKAN API rasmi) dgn ~27,777
                // request tanpa jarak - 200ms sederhana antara sopan & x terlalu perlahankan
                // job yg dah pun berjam-jam sbb HTTP fetch sendiri.
                usleep(200_000);
            }

            $ms = round((microtime(true) - $start) * 1000);
            Log::info("SyncMerchantNicknamesAndImages: selesai - {$processed} kod diproses, {$matched} padan ({$ms}ms).");
        } catch (Throwable $e) {
            Log::error('SyncMerchantNicknamesAndImages gagal: '.$e->getMessage());

            throw $e;
        } finally {
            Cache::forget(self::CACHE_KEY_SYNCING);
        }
    }
}
