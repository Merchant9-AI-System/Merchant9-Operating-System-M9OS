<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Carian LAMAN WEB merchant9.com (BUKAN carian dalaman InventoryPiece) - fallback bila staf
 * cari guna nama gaya/nickname tak formal (cth. "COCO PASIR", "BOBA CAT EYE" - istilah dalaman
 * cawangan drpd proses Excel manual LAMA, rujuk STOCK REQUEST.xlsx yg dikongsi pengguna) yg
 * TAK WUJUD langsung dlm jemisys_inventory_mirror.Description (disahkan carian terus, 0 padanan
 * utk semua nickname yg diuji).
 *
 * PENTING: hasil di sini TIDAK bawa InternalCode JEMiSys yg boleh dipercayai - kod laman web
 * (cth. "CC-035098") disahkan TIDAK padan dgn mana2 InternalCode/InventoryCode dlm sistem
 * (laman web guna katalog/CMS BERASINGAN drpd JEMiSys, disahkan terus tiada padanan). Jadi hasil
 * ni cuma bagi nama/imej/kategori/harga sbg RUJUKAN VISUAL - staf pilih apa yg nampak macam yg
 * dicari, HQ semak & padankan ke stok SEBENAR semasa semakan
 * (App\Filament\Resources\BranchDemandRequests\Pages\ViewBranchDemandRequest) - sama spt proses
 * Excel+gambar asal, cuma didigitalkan (rujuk BranchDemandRequestLine.source_type='web').
 *
 * Guna file_get_contents() (bukan Http::/cURL) - sama sebab dgn ProductImageFetcher (cURL gagal
 * dlm persekitaran Laragon ni sbb isu sijil SSL tempatan).
 */
class MerchantWebsiteSearch
{
    private const SEARCH_URL = 'https://merchant9.com/category/0/0/filter/?search=';

    private const IMAGE_ALLOWED_PATHS = [
        'https://merchant9.com/products/',
        'https://merchant9.com/gallery/products/',
    ];

    private const MAX_RESULTS = 12;

    /**
     * @return array<int, array{name: string, website_code: ?string, category_label: ?string, price_label: ?string, image_url: ?string, product_url: ?string}>
     */
    public static function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        return Cache::remember(
            'merchant_website_search:'.md5(mb_strtolower($term)),
            now()->addHour(),
            fn () => static::fetchAndParse($term)
        );
    }

    /** @return array<int, array{name: string, website_code: ?string, category_label: ?string, price_label: ?string, image_url: ?string, product_url: ?string}> */
    protected static function fetchAndParse(string $term): array
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

            $html = @file_get_contents(self::SEARCH_URL.urlencode($term), false, $context);

            if ($html === false) {
                return [];
            }

            $dom = new DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);
            $cards = $xpath->query('//a[contains(@href, "/product/")]');

            $results = [];
            $seenUrls = [];

            foreach ($cards as $card) {
                if (count($results) >= self::MAX_RESULTS) {
                    break;
                }

                $href = $card->getAttribute('href') ?: null;

                if ($href !== null && isset($seenUrls[$href])) {
                    continue;
                }

                $imageUrl = null;
                foreach ($xpath->query('.//img', $card) as $img) {
                    foreach (['data-src', 'src'] as $attr) {
                        $val = $img->getAttribute($attr);
                        if ($val !== '' && static::isAllowedImagePath($val)) {
                            $imageUrl = $val;
                            break 2;
                        }
                    }
                }

                // "name" & "code laman web" bersarang dlm <strong><span class="item-code"><p>...
                // (urutan 1=nama, 2=kod) - "kategori" & "harga" pula <p> anak TERUS <a> (bukan
                // bersarang) - disahkan drpd struktur HTML sebenar merchant9.com.
                $itemCodeTexts = [];
                foreach ($xpath->query('.//span[contains(@class, "item-code")]', $card) as $span) {
                    $itemCodeTexts[] = trim($span->textContent);
                }

                $directParagraphs = [];
                foreach ($xpath->query('./p', $card) as $p) {
                    $directParagraphs[] = trim($p->textContent);
                }

                $name = $itemCodeTexts[0] ?? null;

                if (blank($name)) {
                    continue;
                }

                if ($href !== null) {
                    $seenUrls[$href] = true;
                }

                $results[] = [
                    'name' => $name,
                    'website_code' => $itemCodeTexts[1] ?? null,
                    'category_label' => $directParagraphs[0] ?? null,
                    'price_label' => $directParagraphs[1] ?? null,
                    'image_url' => $imageUrl,
                    'product_url' => $href,
                ];
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    protected static function isAllowedImagePath(string $url): bool
    {
        foreach (self::IMAGE_ALLOWED_PATHS as $path) {
            if (str_starts_with($url, $path)) {
                return true;
            }
        }

        return false;
    }
}
