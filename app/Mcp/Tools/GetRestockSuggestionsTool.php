<?php

namespace App\Mcp\Tools;

use App\Models\Jemisys\Category;
use App\Support\RestockAnalysisCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Cadangan restock SATU kategori (ikut cawangan) - panggil RestockAnalysisCalculator::
 * byCategoryPerBranch() SAMA seperti App\Filament\Pages\RestockByCategory (SATU sumber
 * kebenaran, bukan kira semula/logik berasingan). PERIOD_ALL sentiasa dipakai (rujuk
 * dokblok kelas tsb - jumlah "sentiasa Semua", velocity/verdict kekal tetingkap TREND_MONTHS).
 *
 * Keputusan dipangkas ke MAX_RESULTS baris (susun ikut 'gap' menurun, drpd calculator SEDIA
 * ADA) - sesetengah kategori ada beribu design (cth. "CINCIN EMAS" 6298 design, disahkan sesi
 * ni), AI assistant perlukan calon TERATAS, bukan longgokan penuh. Baris dipangkas
 * dilaporkan (bukan disorok senyap) via `truncated_count`.
 */
#[Description('Cadangan restock (design + cawangan mana perlu restock, & kenapa) bagi SATU kod kategori - guna list-restock-categories dulu utk dapatkan kod kategori yg sah.')]
#[IsReadOnly]
class GetRestockSuggestionsTool extends Tool
{
    public const MAX_RESULTS = 20;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'category_code' => ['required', 'string'],
            'store_code' => ['nullable', 'string'],
            'only_actionable' => ['nullable', 'boolean'],
        ]);

        $categoryCode = trim($validated['category_code']);
        $onlyActionable = $validated['only_actionable'] ?? true;

        if (! Category::where('CategoryCode', $categoryCode)->exists()) {
            return Response::error("Kod kategori \"{$categoryCode}\" tidak wujud - guna list-restock-categories utk dapatkan kod yg sah.");
        }

        $rows = RestockAnalysisCalculator::byCategoryPerBranch($categoryCode, RestockAnalysisCalculator::PERIOD_ALL);

        if ($storeCode = $validated['store_code'] ?? null) {
            $rows = $rows->where('store_code', trim($storeCode));
        }

        if ($onlyActionable) {
            $rows = $rows->where('verdict', '!=', RestockAnalysisCalculator::VERDICT_OK);
        }

        $totalCount = $rows->count();
        $rows = $rows->take(self::MAX_RESULTS);

        $suggestions = $rows->map(fn (array $row) => [
            'internal_code' => $row['internal_code'],
            'description' => $row['description'],
            'store_code' => $row['store_code'],
            'current_stock' => $row['current_stock'],
            'target_stock' => $row['target_stock'],
            'gap' => $row['gap'],
            'verdict' => $row['verdict'],
        ])->values()->all();

        return Response::structured([
            'category_code' => $categoryCode,
            'total_count' => $totalCount,
            'truncated_count' => max(0, $totalCount - count($suggestions)),
            'suggestions' => $suggestions,
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_code' => $schema->string()
                ->description('Kod kategori (cth. "RT") - dapatkan drpd list-restock-categories.')
                ->required(),
            'store_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU cawangan (cth. "PERLING"). Kosong = semua cawangan.'),
            'only_actionable' => $schema->boolean()
                ->description('Lalai true - sorok baris "Stok Cukup" (verdict OK), pulangkan hanya design yg PERLU tindakan.')
                ->default(true),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'category_code' => $schema->string()->required(),
            'total_count' => $schema->integer()->description('Jumlah baris sepadan sebelum dipangkas.')->required(),
            'truncated_count' => $schema->integer()->description('Bilangan baris disorok kerana melebihi had '.self::MAX_RESULTS.'.')->required(),
            'suggestions' => $schema->array()->description('Senarai cadangan restock, susun ikut gap menurun.')->required(),
        ];
    }
}
