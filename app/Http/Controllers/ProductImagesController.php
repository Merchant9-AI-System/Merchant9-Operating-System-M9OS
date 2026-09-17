<?php

namespace App\Http\Controllers;

use App\Support\ProductImageFetcher;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Senarai PENUH imej produk (bukan cuma 1 thumbnail) bagi satu InternalCode - dibuka drpd lajur
 * imej ImageColumn di Filament (rujuk App\Filament\Pages\StockRearrangementRecommendation),
 * ganti kelakuan asal (->url() terus ke 1 imej mentah, buka tab baharu) dgn halaman Inertia ni
 * yg papar SEMUA imej dikikis drpd storefront (rujuk ProductImageFetcher::imageUrlsFor()) -
 * staf boleh nampak semua angle/variasi gambar produk, bukan cuma gambar pertama.
 */
class ProductImagesController extends Controller
{
    public function show(string $internalCode): Response
    {
        return Inertia::render('ProductImages/Index', [
            'internalCode' => $internalCode,
            'images' => ProductImageFetcher::imageUrlsFor($internalCode),
        ]);
    }
}
