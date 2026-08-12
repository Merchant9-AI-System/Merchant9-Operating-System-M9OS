<?php

namespace App\Http\Controllers;

use App\Models\Jemisys\InventoryPiece;
use App\Support\JobsheetRestockScorer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Carian item mengikut JobSheetNo (jemisys_inventory_mirror) - staf log masuk sahaja (rujuk
 * routes/web.php, middleware 'auth'). Padanan TEPAT (bukan separa) - JobSheetNo ialah CHAR
 * padded (varchar(10), cth. "JS000001  "), WAJIB trim() dua-dua belah spt lajur Jemisys lain.
 * Input carian LONGGAR - "JS8679"/"js8679"/"8679" dinormalize ke format tepat sblm carian
 * (rujuk normalizeJobsheetNo()).
 *
 * SATU route Inertia (bukan fetch() ke endpoint JSON berasingan) - carian ialah GET biasa ke
 * halaman yg SAMA dgn query string ?jobsheet=..., Inertia render props baharu (rujuk
 * router.get() di Index.vue, preserveState elak flash/reset skrol).
 *
 * `items` DITANGGUH (Inertia::defer()) - carian boleh pulangkan beribu baris (satu job sheet
 * besar boleh cecah 2000+ keping) + JobsheetRestockScorer kira skor per design/kategori, jadi
 * shell halaman (tajuk/borang carian) terus terpapar SEBELUM hasil siap, elak UI nampak
 * "freeze" sekejap - rujuk <Deferred> & <Skeleton> di Index.vue.
 */
class JobsheetLookupController extends Controller
{
    public function index(Request $request): Response
    {
        $jobsheet = trim((string) $request->query('jobsheet', ''));
        $jobsheet = mb_substr($jobsheet, 0, 10);
        $jobsheet = $this->normalizeJobsheetNo($jobsheet);

        return Inertia::render('JobsheetLookup/Index', [
            'jobsheet' => $jobsheet,
            'hasSearched' => filled($jobsheet),
            'items' => Inertia::defer(fn () => $this->searchItems($jobsheet)),
        ]);
    }

    /**
     * Terima input longgar - "JS8679", "js8679", atau "8679" sahaja - dan bina semula format
     * TEPAT JobSheetNo Jemisys ("JS" + 6 digit zero-padded, cth. "JS008679"). Ambil digit SAHAJA
     * drpd input (buang "JS"/kes huruf/ruang), pad ke 6 digit. Kalau digit >6 atau tiada digit
     * langsung, pulangkan input asal tanpa diubah - carian tepat (TRIM(JobSheetNo) = ?) akan
     * sekadar tiada padanan, sama spt sebelum ni.
     */
    protected function normalizeJobsheetNo(string $input): string
    {
        $digits = preg_replace('/\D/', '', $input);

        if ($digits === '' || strlen($digits) > 6) {
            return $input;
        }

        return 'JS'.str_pad($digits, 6, '0', STR_PAD_LEFT);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function searchItems(string $jobsheet): Collection
    {
        if (blank($jobsheet)) {
            return collect();
        }

        $items = InventoryPiece::query()
            ->with(['vendor', 'category', 'store'])
            ->whereRaw('TRIM(JobSheetNo) = ?', [$jobsheet])
            ->orderByDesc('PurchDate')
            ->get()
            ->map(fn (InventoryPiece $piece) => [
                'inventory_code' => $piece->InventoryCode,
                'internal_code' => filled($piece->InternalCode) ? trim($piece->InternalCode) : null,
                'description' => $piece->Description,
                'category_name' => $piece->category?->Description,
                'store_code' => filled($piece->StoreCode) ? trim($piece->StoreCode) : null,
                'vendor_name' => $piece->vendor?->Description,
                'size' => filled($piece->JewelSize) ? trim((string) $piece->JewelSize) : null,
                'weight' => $piece->GoldWeight !== null ? (float) $piece->GoldWeight : null,
                'qty_on_hand' => (int) $piece->QtyOnHand,
                'status' => $piece->Status,
                'purch_date' => $piece->PurchDate?->format('d/m/Y'),
                'sales_date' => $piece->SalesDate?->format('d/m/Y'),
                'image_url' => $piece->image_url,
                'nickname' => $piece->nickname,
            ])
            ->values();

        return $this->attachRestockSuggestions($items);
    }

    /**
     * Lekat cadangan restock (skor 0-100 + sebab + cawangan disyorkan) setiap baris - rujuk
     * JobsheetRestockScorer dokblok utk 4 isyarat (Stok Habis/Understock/Design Paling
     * Laku/Cawangan Jualan Tertinggi). Skor boleh BEZA antara baris SATU design yg sama jika
     * berlainan cawangan (rujuk isyarat #4), tapi `restock_target_branches` (StoreCode top 3
     * cawangan plg laku design tsb) SAMA utk semua baris design tsb - jawab "patut hantar ke
     * mana", bukan skor semata-mata.
     */
    protected function attachRestockSuggestions(Collection $items): Collection
    {
        $scores = JobsheetRestockScorer::scoreRows($items);

        return $items->map(function (array $item) use ($scores) {
            $key = "{$item['internal_code']}|{$item['store_code']}";
            $score = $scores[$key] ?? ['score' => 0, 'verdict' => null, 'verdict_color' => 'gray', 'reasons' => [], 'target_branches' => []];

            return [
                ...$item,
                'restock_score' => $score['score'],
                'restock_verdict' => $score['verdict'],
                'restock_verdict_color' => $score['verdict_color'],
                'restock_reasons' => $score['reasons'],
                'restock_target_branches' => $score['target_branches'],
            ];
        });
    }
}
