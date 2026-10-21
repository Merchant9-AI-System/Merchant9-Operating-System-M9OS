<?php

namespace App\Support;

use App\Models\InventoryMirror;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Imej produk bagi satu InternalCode, dikikis drpd storefront merchant9.com (rujuk repo-root
 * image-retreive.php - skrip taburan bebas, BUKAN sebahagian app Laravel - kelas ni port logik
 * yg sama [fetch + DOMDocument parse + tapis path] ke dlm app, cached, supaya boleh dipanggil
 * terus drpd ImageColumn (rujuk StockRearrangementRecommendation) tanpa scrape semula setiap
 * page load.
 *
 * Guna file_get_contents() (stream context), BUKAN Illuminate\Support\Facades\Http (cURL/Guzzle)
 * - disahkan cURL gagal dlm persekitaran Laragon ni ("error setting certificate file:
 * C:\laragon\etc\ssl\cacert.pem"), sepadan komen sedia ada pd image-retreive.php sendiri
 * ("using file_get_contents as a fallback (no cURL)") - pengarang asal dah pernah hadapi isu
 * SSL cURL yg sama & sengaja elak guna cURL sebab itu.
 *
 * Cache::remember (1 hari, BUKAN forever) - foto produk jarang berubah tapi BUKAN "kalkulator
 * DB" yg di-invalidate serentak dgn SyncJemisysMirrors (rujuk pattern Cache::rememberForever
 * kalkulator App\Support lain) - fetch luaran ni tiada isyarat "bila nak refresh" yg sama, jadi
 * TTL tetap lebih selamat drpd forever.
 *
 * SUMBER UTAMA = cermin: gambar yg sudah disegerak/diwarisi dlm jemisys_inventory_mirror.image_url (rujuk
 * SyncMerchantNicknamesAndImages & App\Support\DesignFamily) didahulukan utk SATU gambar (firstImageUrlFor) dan sbg
 * sandaran bila kikisan langsung tiada hasil (firstImageUrlsFor) - supaya nama (drpd cermin) & gambar sentiasa konsisten di
 * semua skrin, dan kod berkeluarga yg tiada padanan laman web sendiri (cth. RTATAU04K10G) turut dapat gambar. Kikis
 * langsung (cache 1 hari) hanya bila cermin tiada gambar.
 */
class ProductImageFetcher
{
    private const SEARCH_URL = 'https://merchant9.com/category/0/0/filter/?search=';

    private const ORIGIN = 'https://merchant9.com';

    private const ALLOWED_PATHS = [
        'https://merchant9.com/products/',
        'https://merchant9.com/gallery/products/',
    ];

    /**
     * Semua imej produk (dipisah/dedupe) bagi satu design - satu scrape+cache SAHAJA, dikongsi
     * dgn firstImageUrlFor() di bawah supaya panggil kedua-dua method utk design yg sama tidak
     * scrape dua kali. Array (bukan nullable string) - Cache::remember() tak masalah dgn array
     * kosong (tak spt null, rujuk nota firstImageUrlFor() versi lama), jadi "design tiada imej"
     * turut cache dgn betul tanpa perlu sentinel value.
     *
     * @return array<int, string>
     */
    public static function imageUrlsFor(string $internalCode): array
    {
        return Cache::remember(
            'product_images:'.$internalCode,
            now()->addDay(),
            fn () => static::fetchImages($internalCode),
        );
    }

    /**
     * Imej pertama sahaja - cth. utk thumbnail ImageColumn (rujuk StockRearrangementRecommendation). Cermin didahulukan
     * (tiada HTTP), kikisan langsung hanya sandaran.
     */
    public static function firstImageUrlFor(string $internalCode): ?string
    {
        return static::mirrorImageFor($internalCode) ?? (static::imageUrlsFor($internalCode)[0] ?? null);
    }

    /**
     * Gambar design dlm cermin (disegerak ATAU diwarisi keluarga), atau null. Padanan tepat tanpa TRIM (InternalCode di cermin
     * berpadding & kolasi PAD SPACE - "KOD" sama dgn "KOD   ") supaya indeks InternalCode digunakan.
     */
    public static function mirrorImageFor(string $internalCode): ?string
    {
        $code = trim($internalCode);

        if ($code === '') {
            return null;
        }

        $url = InventoryMirror::query()
            ->where('InternalCode', $code)
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->value('image_url');

        return filled($url) ? (string) $url : null;
    }

    /**
     * 3 imej pertama sahaja (lalai) - cth. utk preview/carousel yg perlukan lebih drpd 1 gambar
     * tapi tak perlu SEMUA (rujuk firstImageUrlFor() utk kes 1 gambar). Guna imageUrlsFor() yg
     * sama (sudah cached) - TIADA scrape tambahan, sekadar potong array sedia ada.
     *
     * @return array<int, string>
     */
    public static function firstImageUrlsFor(string $internalCode, int $limit = 3): array
    {
        $live = array_slice(static::imageUrlsFor($internalCode), 0, $limit);

        // Kikisan tiada hasil (cth. kod berkeluarga tanpa padanan laman web sendiri) -> guna gambar cermin kalau ada.
        if ($live === [] && ($mirror = static::mirrorImageFor($internalCode)) !== null) {
            return [$mirror];
        }

        return $live;
    }

    /**
     * @return array<int, string>
     */
    protected static function fetchImages(string $internalCode): array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: Mozilla/5.0 (compatible; ImageFetcher/1.0)\r\n",
                    'timeout' => 10,
                    'follow_location' => 1,
                ],
            ]);

            $html = @file_get_contents(self::SEARCH_URL.urlencode($internalCode), false, $context);

            if ($html === false) {
                return [];
            }

            $dom = new DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();

            $images = [];

            foreach ($dom->getElementsByTagName('img') as $img) {
                foreach (['src', 'data-src'] as $attr) {
                    $value = $img->getAttribute($attr);

                    if ($value === '') {
                        continue;
                    }

                    $url = static::resolveUrl($value);

                    foreach (self::ALLOWED_PATHS as $path) {
                        if (str_starts_with($url, $path)) {
                            $images[] = $url;

                            break;
                        }
                    }
                }
            }

            return array_values(array_unique($images));
        } catch (Throwable) {
            // Storefront down/lembab/tak dapat dicapai - jangan pecahkan table kerana ni.
            return [];
        }
    }

    /** Ported drpd image-retreive.php resolveUrl() - origin ditetap ke merchant9.com sahaja. */
    protected static function resolveUrl(string $src): string
    {
        if (parse_url($src, PHP_URL_SCHEME) !== null) {
            return $src;
        }

        if (str_starts_with($src, '//')) {
            return 'https:'.$src;
        }

        if (str_starts_with($src, '/')) {
            return self::ORIGIN.$src;
        }

        return self::ORIGIN.'/'.ltrim($src, '/');
    }
}
