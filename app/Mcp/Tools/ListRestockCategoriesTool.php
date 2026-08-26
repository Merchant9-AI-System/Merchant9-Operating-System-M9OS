<?php

namespace App\Mcp\Tools;

use App\Models\Jemisys\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Senarai kod kategori (cth. "RT" = RANTAI TANGAN) - sumber SAMA dgn SelectFilter kategori di
 * RestockByCategory/RestockByWeight/RestockBySize. Panggil ni DULU sebelum get-restock-suggestions
 * sbb AI assistant x akan tahu kod kategori terus drpd nama biasa.
 */
#[Description('Senaraikan semua kod kategori restock Merchant9 (kod + nama) - panggil ni dulu utk cari kod kategori (cth. "RT") sebelum guna get-restock-suggestions.')]
#[IsReadOnly]
class ListRestockCategoriesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $categories = Category::where('CategoryCode', '!=', '')
            ->orderBy('Description')
            ->get(['CategoryCode', 'Description'])
            ->map(fn (Category $category) => [
                'code' => trim($category->CategoryCode),
                'name' => $category->Description,
            ])
            ->values()
            ->all();

        return Response::structured(['categories' => $categories]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
