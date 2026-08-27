<?php

namespace App\Mcp\Tools;

use App\Models\Jemisys\InventoryPiece;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Carian stok fizikal semasa (on-hand) - sumber data & skop SAMA dgn
 * App\Filament\Resources\InventoryPieces\InventoryPieceResource (onHand() sahaja, SENGAJA
 * TIADA realVendor() - "Stok Semasa" papar SEMUA stok fizikal termasuk vendor placeholder,
 * bukan skop analitik). Read-only sepenuhnya - resource asal pun tiada create/edit/delete.
 *
 * #[Name(...)] WAJIB - rujuk nota sama di ListRestockCategoriesTool.
 */
#[Name('lookup-inventory-pieces')]
#[Description('Cari stok fizikal semasa (on-hand) - ikut kod design (carian separa), kategori, cawangan, supplier, atau purity. Papar SEMUA stok fizikal termasuk vendor placeholder.')]
#[IsReadOnly]
class LookupInventoryPiecesTool extends Tool
{
    public const MAX_RESULTS = 20;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'internal_code' => ['nullable', 'string'],
            'category_code' => ['nullable', 'string'],
            'store_code' => ['nullable', 'string'],
            'vendor_code' => ['nullable', 'string'],
            'class_code' => ['nullable', 'string'],
        ]);

        // whereRaw(TRIM(...)) - CategoryCode/StoreCode/VendorCode/ClassCode SEMUA lajur CHAR
        // terpadding dlm jemisys_inventory_mirror (cth. "DAMAI" + ruang hingga 20 aksara,
        // disahkan sebenar via tinker) - padanan exact tanpa TRIM() GAGAL senyap utk pemanggil
        // luar (agen AI x akan tahu bilangan ruang tepat). InternalCode guna LIKE %...% sedia
        // ada - padding tak jejaskan carian separa.
        $query = InventoryPiece::query()
            ->onHand()
            ->when($validated['internal_code'] ?? null, fn ($q, $code) => $q->where('InternalCode', 'like', '%'.trim($code).'%'))
            ->when($validated['category_code'] ?? null, fn ($q, $code) => $q->whereRaw('TRIM(CategoryCode) = ?', [trim($code)]))
            ->when($validated['store_code'] ?? null, fn ($q, $code) => $q->whereRaw('TRIM(StoreCode) = ?', [trim($code)]))
            ->when($validated['vendor_code'] ?? null, fn ($q, $code) => $q->whereRaw('TRIM(VendorCode) = ?', [trim($code)]))
            ->when($validated['class_code'] ?? null, fn ($q, $code) => $q->whereRaw('TRIM(ClassCode) = ?', [trim($code)]));

        $totalCount = (clone $query)->count();

        $pieces = $query->orderByDesc('PurchDate')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (InventoryPiece $piece) => [
                'internal_code' => trim($piece->InternalCode),
                'description' => $piece->Description,
                'category_code' => trim((string) $piece->CategoryCode),
                'vendor_code' => trim((string) $piece->VendorCode),
                'store_code' => trim((string) $piece->StoreCode),
                'class_code' => trim((string) $piece->ClassCode),
                'jewel_size' => $piece->JewelSize,
                'gold_weight' => $piece->GoldWeight,
                'total_cost' => $piece->TotalCost,
                'qty_on_hand' => $piece->QtyOnHand,
                'purch_date' => $piece->PurchDate?->toDateString(),
                'age_days' => $piece->age_days,
            ])
            ->values()
            ->all();

        return Response::structured([
            'total_count' => $totalCount,
            'truncated_count' => max(0, $totalCount - count($pieces)),
            'pieces' => $pieces,
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'internal_code' => $schema->string()
                ->description('Pilihan - carian separa atas kod design (cth. "RT-001").'),
            'category_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU kod kategori (cth. "RT") - dapatkan drpd list-restock-categories.'),
            'store_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU cawangan (cth. "PERLING").'),
            'vendor_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU kod supplier.'),
            'class_code' => $schema->string()
                ->description('Pilihan - purity (cth. "916").'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'total_count' => $schema->integer()->description('Jumlah piece sepadan sebelum dipangkas.')->required(),
            'truncated_count' => $schema->integer()->description('Bilangan disorok kerana melebihi had '.self::MAX_RESULTS.'.')->required(),
            'pieces' => $schema->array()->description('Senarai piece stok, susun ikut tarikh beli menurun.')->required(),
        ];
    }
}
