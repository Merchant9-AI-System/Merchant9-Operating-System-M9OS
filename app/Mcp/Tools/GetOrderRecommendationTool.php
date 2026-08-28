<?php

namespace App\Mcp\Tools;

use App\Models\Jemisys\Category;
use App\Support\OrderRecommendationCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Cadangan "Open-to-Buy" (design mana patut diorder, berapa banyak) - panggil
 * OrderRecommendationCalculator::recommendations() SAMA seperti widget
 * App\Filament\Widgets\BuyRecommendations (SATU sumber kebenaran, port disahkan padan Python
 * procurement_report.py). Hanya design "sihat" (pieces_received/sell_through_rate cukup) &
 * recommend_qty > 0 - rujuk dokblok kelas tsb utk formula penuh.
 *
 * vendor_code/category_code drpd calculator ni PADDED (CHAR(20) mentah drpd TblInventory,
 * pattern sama spt GetRestockByBucketTool) - trim() kedua-dua belah setiap perbandingan filter
 * DI SINI, & nilai output ditrim jugak.
 *
 * ~2600+ baris tanpa tapisan (disahkan Tinker) - lalai susun ikut recommend_qty menurun (bukan
 * susunan asal vendor_code->recommend_qty drpd calculator, yg tak berguna utk soalan "apa PALING
 * perlu diorder" tanpa vendor_code ditetapkan) & pangkas ke MAX_RESULTS, sepadan tool lain.
 */
#[Name('get-order-recommendation')]
#[Description('Cadangan Open-to-Buy (design + kuantiti patut diorder drpd vendor) - boleh tapis ikut vendor_code atau category_code.')]
#[IsReadOnly]
class GetOrderRecommendationTool extends Tool
{
    public const MAX_RESULTS = 20;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'vendor_code' => ['nullable', 'string'],
            'category_code' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'min_recommend_qty' => ['nullable', 'integer', 'min:1'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $offset = $validated['offset'] ?? 0;

        if ($rawCategoryCode = $validated['category_code'] ?? null) {
            $categoryCode = trim($rawCategoryCode);

            if (! Category::where('CategoryCode', $categoryCode)->exists()) {
                return Response::error("Kod kategori \"{$categoryCode}\" tidak wujud - guna list-restock-categories utk dapatkan kod yg sah.");
            }
        }

        $rows = OrderRecommendationCalculator::recommendations();

        if (isset($categoryCode)) {
            $rows = $rows->filter(fn (array $row) => trim((string) $row['category_code']) === $categoryCode);
        }

        if ($vendorCode = $validated['vendor_code'] ?? null) {
            $vendorCode = trim($vendorCode);
            $rows = $rows->filter(fn (array $row) => trim((string) $row['vendor_code']) === $vendorCode);
        }

        if ($minQty = $validated['min_recommend_qty'] ?? null) {
            $rows = $rows->where('recommend_qty', '>=', $minQty);
        }

        if (filled($validated['search'] ?? null)) {
            $needle = mb_strtolower($validated['search']);
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower((string) $row['internal_code']), $needle)
                || str_contains(mb_strtolower((string) $row['item_desc']), $needle));
        }

        $totalCount = $rows->count();
        // Susun semula ikut recommend_qty menurun utk output tool ni - rujuk dokblok kelas.
        // TIDAK ubah susunan cache asal OrderRecommendationCalculator (vendor_code->recommend_qty).
        $rows = $rows->sortByDesc('recommend_qty')->values()->slice($offset, self::MAX_RESULTS);

        $result = $rows->map(fn (array $row) => [
            'vendor_code' => trim((string) $row['vendor_code']),
            'vendor_name' => $row['vendor_name'],
            'internal_code' => trim((string) $row['internal_code']),
            'item_desc' => $row['item_desc'],
            'category_code' => trim((string) $row['category_code']),
            'current_stock' => $row['current_stock'],
            'target_stock' => $row['target_stock'],
            'recommend_qty' => $row['recommend_qty'],
            'sell_through_rate' => $row['sell_through_rate'],
            'velocity_per_month' => $row['velocity_per_month'],
            'cover_months' => $row['cover_months'],
        ])->values()->all();

        return Response::structured([
            'total_count' => $totalCount,
            'offset' => $offset,
            'truncated_count' => max(0, $totalCount - $offset - count($result)),
            'recommendations' => $result,
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'vendor_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU kod vendor (cth. "ACE").'),
            'category_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU kod kategori (cth. "RT") - dapatkan drpd list-restock-categories.'),
            'search' => $schema->string()
                ->description('Pilihan - cari ikut kod design (InternalCode) atau perihal item, sepadan sebahagian.'),
            'min_recommend_qty' => $schema->integer()
                ->description('Pilihan - hanya pulangkan design dgn recommend_qty >= nilai ni.'),
            'offset' => $schema->integer()
                ->description('Pilihan - langkau seberapa banyak baris (halaman seterusnya) - lalai 0. Cth. offset=20 utk baris 21-40.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'total_count' => $schema->integer()->description('Jumlah baris sepadan sebelum dipangkas.')->required(),
            'offset' => $schema->integer()->description('Offset yg dipakai.')->required(),
            'truncated_count' => $schema->integer()->description('Bilangan baris disorok kerana melebihi had '.self::MAX_RESULTS.' selepas offset.')->required(),
            'recommendations' => $schema->array()->description('Senarai cadangan order, susun ikut recommend_qty menurun.')->required(),
        ];
    }
}
