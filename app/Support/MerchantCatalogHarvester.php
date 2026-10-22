<?php

namespace App\Support;

use App\Models\InventoryMirror;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Tuai SELURUH pemetaan produk laman web merchant9.com -> kod design (senarai kuning pada kad produk) dan isi nickname/gambar
 * kod design JEMiSys yg disenaraikan (rujuk App\Support\CatalogCodeMap utk peraturan & MerchantWebsiteSearch::fetchAll()).
 *
 * Carian laman web memadan awalan kod PERTAMA produk (min. 4 aksara) & memaparkan senarai penuh kodnya - jadi setiap awalan
 * 4-huruf yg wujud dlm inventori dicari (~1,900 permintaan, ~30 minit), kemudian awalan kod BARU yg ditemui dicari juga
 * (sehingga MAX_EXTRA_ROUNDS pusingan) supaya produk yg kod pertamanya tiada dlm inventori turut tertuai.
 *
 * Hasil tuaian disimpan di storage/app/merchant-catalog-harvest.json supaya pelan boleh dikira semula tanpa menuai lagi.
 */
class MerchantCatalogHarvester
{
    public const CACHE_FILE = 'merchant-catalog-harvest.json';

    private const MAX_EXTRA_ROUNDS = 2;

    private const THROTTLE_MICROSECONDS = 250_000;

    /** @return array<int, string> awalan 4-huruf unik drpd InternalCode inventori */
    public function prefixes(): array
    {
        return InventoryMirror::query()
            ->whereNotNull('InternalCode')
            ->selectRaw('UPPER(LEFT(TRIM(InternalCode), 4)) as prefix')
            ->groupBy('prefix')
            ->toBase()
            ->pluck('prefix')
            ->filter(fn ($p) => preg_match('/^[A-Z]{4}$/', (string) $p) === 1)
            ->values()
            ->all();
    }

    /**
     * @param  callable(int $done, int $total, int $cards): void|null  $progress
     * @return array<string, array{name: ?string, image_url: ?string, product_url: ?string, design_codes: array<int, string>}> kad ikut product_url
     */
    public function harvest(?callable $progress = null, ?int $limit = null): array
    {
        $queried = [];
        $cards = [];
        $queue = $this->prefixes();

        if ($limit !== null) {
            $queue = array_slice($queue, 0, $limit);
        }

        for ($round = 0; $round <= self::MAX_EXTRA_ROUNDS && $queue !== []; $round++) {
            $total = count($queue);

            foreach ($queue as $i => $prefix) {
                $queried[$prefix] = true;

                foreach (MerchantWebsiteSearch::fetchAll($prefix) as $card) {
                    $key = $card['product_url'] ?? ($card['website_code'] ?? $card['name']);
                    $cards[$key] = ['name' => $card['name'], 'image_url' => $card['image_url'], 'product_url' => $card['product_url'], 'design_codes' => $card['design_codes']];
                }

                $progress?->__invoke($i + 1, $total, count($cards));
                usleep(self::THROTTLE_MICROSECONDS);
            }

            // Awalan kod baharu yg ditemui (kod kedua/ketiga) yg belum dicari - kad lain mungkin bermula dgn awalan itu.
            $queue = [];

            if ($limit === null) {
                foreach ($cards as $card) {
                    foreach ($card['design_codes'] as $code) {
                        $prefix = substr($code, 0, 4);

                        if (preg_match('/^[A-Z]{4}$/', $prefix) === 1 && ! isset($queried[$prefix])) {
                            $queue[$prefix] = $prefix;
                        }
                    }
                }

                $queue = array_values($queue);
            }
        }

        return $cards;
    }

    /**
     * @return array{rows: array<string, array<string, mixed>>, raw: array<string, array<int, string>>} nilai semasa ikut kod (trim, UPPER) & kod mentah
     */
    public function currentRows(): array
    {
        $rows = [];
        $raw = [];

        InventoryMirror::query()
            ->whereNotNull('InternalCode')
            ->where('InternalCode', '!=', '')
            ->selectRaw('InternalCode as raw_code, MAX(nickname) as nickname, MAX(image_url) as image_url, MAX(nickname_source) as nickname_source, MAX(image_source) as image_source')
            ->groupBy('InternalCode')
            ->toBase()
            ->get()
            ->each(function ($r) use (&$rows, &$raw) {
                $code = strtoupper(trim((string) $r->raw_code));
                $raw[$code][] = $r->raw_code;
                $rows[$code] = [
                    'nickname' => $r->nickname ?? ($rows[$code]['nickname'] ?? null),
                    'image_url' => $r->image_url ?? ($rows[$code]['image_url'] ?? null),
                    'nickname_source' => $r->nickname_source ?? ($rows[$code]['nickname_source'] ?? null),
                    'image_source' => $r->image_source ?? ($rows[$code]['image_source'] ?? null),
                ];
            });

        return ['rows' => $rows, 'raw' => $raw];
    }

    /**
     * Kira pelan drpd tuaian (dibaca drpd fail cache bila $fromCache, jika ada) & laksanakan bila bukan dry-run.
     *
     * @param  callable(int $done, int $total, int $cards): void|null  $progress
     * @return array{plan: array<string, mixed>, cards: int, harvested: bool}
     */
    public function run(bool $dryRun = true, bool $fromCache = false, ?callable $progress = null, ?int $limit = null): array
    {
        $disk = Storage::disk('local');
        $harvested = false;

        if ($fromCache && $disk->exists(self::CACHE_FILE)) {
            $cards = json_decode((string) $disk->get(self::CACHE_FILE), true) ?: [];
        } else {
            $cards = $this->harvest($progress, $limit);
            $disk->put(self::CACHE_FILE, json_encode($cards));
            $harvested = true;
        }

        $current = $this->currentRows();
        $plan = CatalogCodeMap::plan(array_values($cards), $current['rows']);

        if (! $dryRun) {
            foreach ($plan['updates'] as $code => $fields) {
                InventoryMirror::whereIn('InternalCode', $current['raw'][$code])->update($fields);
            }

            Log::info('MerchantCatalogHarvester: '.count($cards).' produk dituai, '.count($plan['updates']).' kod dikemas kini '.json_encode($plan['stats']));
        }

        return ['plan' => $plan, 'cards' => count($cards), 'harvested' => $harvested];
    }
}
