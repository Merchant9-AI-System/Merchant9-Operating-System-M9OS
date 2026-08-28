<?php

namespace App\Mcp\Tools;

use App\Models\StockoutReorderCandidate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Design pernah laku (>=3 pcs) tapi kini stok=0 di semua saluran - calon reorder segera.
 * Sumber data & penapisan SAMA dgn App\Filament\Pages\StockoutReorder
 * (StockoutReorderCandidate::candidateQuery(), rujuk dokblok kelas tsb utk rasional grain
 * jadual pra-agregat). "Stok Repair"/"Sold By Branch" dikira berasingan per-baris yg
 * dipulangkan (padan cara page asal - rujuk repairQtyOnHandFor()/soldByBranchFor()), BUKAN
 * utk semua ~27K design.
 *
 * Lalai range = "overall" (padan default candidateQuery() sendiri) - BUKAN "7d" spt UI page
 * asal (default UI tsb khusus utk paparan staf, bukan tingkah laku data sumber).
 *
 * #[Name(...)] WAJIB - rujuk nota sama di ListRestockCategoriesTool.
 */
#[Name('get-stockout-reorder-candidates')]
#[Description('Design pernah laku (>=3 pcs terjual, ikut julat tarikh) tapi kini stok=0 di semua saluran - calon reorder segera, susun ikut jumlah terjual menurun.')]
#[IsReadOnly]
class GetStockoutReorderCandidatesTool extends Tool
{
    public const MAX_RESULTS = 20;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'range' => ['nullable', 'string', 'in:'.implode(',', array_keys(StockoutReorderCandidate::RANGE_DAYS))],
            'category_code' => ['nullable', 'string'],
            'vendor_codes' => ['nullable', 'array'],
            'vendor_codes.*' => ['string'],
            'exclude_vendor_codes' => ['nullable', 'array'],
            'exclude_vendor_codes.*' => ['string'],
            'exclude_store_codes' => ['nullable', 'array'],
            'exclude_store_codes.*' => ['string'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $range = $validated['range'] ?? StockoutReorderCandidate::RANGE_OVERALL;
        $offset = $validated['offset'] ?? 0;
        $vendorCodes = $validated['vendor_codes'] ?? [];
        $excludeVendorCodes = $validated['exclude_vendor_codes'] ?? [];
        $excludeStoreCodes = $validated['exclude_store_codes'] ?? [];

        $query = StockoutReorderCandidate::candidateQuery(
            includedVendorCodes: $vendorCodes,
            excludedVendorCodes: $excludeVendorCodes,
            excludedStoreCodes: $excludeStoreCodes,
            range: $range,
        );

        // whereRaw(TRIM(...)) - CategoryCode lajur CHAR terpadding (cth. "RT" + 18 ruang,
        // disahkan sebenar via tinker) walaupun StoreCode/VendorCode jadual pra-agregat ni
        // TIDAK terpadding (bersih drpd StockoutReorderMaterializer) - padanan exact tanpa
        // TRIM() GAGAL senyap.
        if ($categoryCode = $validated['category_code'] ?? null) {
            $query->whereRaw('TRIM(CategoryCode) = ?', [trim($categoryCode)]);
        }

        // Query dah agregat (GROUP BY InternalCode HAVING ...) - COUNT baris terus (bukan
        // COUNT(*) SQL, yg akan kira per-group bukan jumlah design) perlukan get()->count()
        // atas klon SEBELUM limit() dipakai (rujuk dataset ~131.8K baris mentah -> jauh lebih
        // kecil selepas GROUP BY/HAVING, jadi get() penuh di sini kekal murah).
        $totalCount = (clone $query)->get()->count();

        $rows = $query->orderByDesc('sold_count')->offset($offset)->limit(self::MAX_RESULTS)->get();

        $candidates = $rows->map(fn (StockoutReorderCandidate $row) => [
            'internal_code' => $row->InternalCode,
            'description' => $row->Description,
            'category_code' => trim((string) $row->CategoryCode),
            'sold_count' => (int) $row->sold_count,
            'qty_on_hand' => (int) $row->qty_on_hand,
            'last_sale_date' => $row->last_sale_date?->toDateString(),
            'vendor_codes' => $row->vendorCodes(),
            'repair_qty_on_hand' => StockoutReorderCandidate::repairQtyOnHandFor($row->InternalCode, excludedStoreCodes: $excludeStoreCodes),
            'sold_by_branch' => StockoutReorderCandidate::soldByBranchFor(
                $row->InternalCode,
                $vendorCodes,
                $excludeVendorCodes,
                excludedStoreCodes: $excludeStoreCodes,
            ),
        ])->values()->all();

        return Response::structured([
            'range' => $range,
            'total_count' => $totalCount,
            'offset' => $offset,
            'truncated_count' => max(0, $totalCount - $offset - count($candidates)),
            'candidates' => $candidates,
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'range' => $schema->string()
                ->description('Pilihan - julat tarikh jualan: "7d", "30d", "90d", "180d", "365d", atau "overall" (lalai).'),
            'category_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU kod kategori (cth. "RT").'),
            'vendor_codes' => $schema->array()
                ->description('Pilihan - hanya kira supplier ni (senarai kod vendor).'),
            'exclude_vendor_codes' => $schema->array()
                ->description('Pilihan - kecualikan supplier ni drpd kiraan.'),
            'exclude_store_codes' => $schema->array()
                ->description('Pilihan - kecualikan cawangan ni drpd kiraan (anggap cawangan tsb "tak wujud" merentasi semua angka).'),
            'offset' => $schema->integer()
                ->description('Pilihan - langkau seberapa banyak baris (halaman seterusnya) - lalai 0. Cth. offset=20 utk baris 21-40.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'range' => $schema->string()->description('Julat tarikh yg dipakai.')->required(),
            'total_count' => $schema->integer()->description('Jumlah design layak sebelum dipangkas.')->required(),
            'offset' => $schema->integer()->description('Offset yg dipakai.')->required(),
            'truncated_count' => $schema->integer()->description('Bilangan disorok kerana melebihi had '.self::MAX_RESULTS.' selepas offset.')->required(),
            'candidates' => $schema->array()->description('Senarai calon reorder, susun ikut sold_count menurun.')->required(),
        ];
    }
}
