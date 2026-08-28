<?php

namespace App\Mcp\Tools;

use App\Models\Jemisys\Category;
use App\Support\RestockAnalysisCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Cadangan restock silang Kategori x Cawangan, per Saiz ATAU per Berat - panggil
 * RestockAnalysisCalculator::bySize()/byWeight() SAMA seperti App\Filament\Pages\RestockBySize/
 * RestockByWeight (SATU sumber kebenaran). Satu tool dgn parameter `grain` gantikan dua tool
 * berasingan sbb kedua-dua page kongsi struktur output SAMA (cuma bucket Saiz vs Berat).
 *
 * category_code/store_code drpd bySize()/byWeight() ialah PADDED (CHAR(20) mentah drpd
 * TblInventory, disahkan langsung via Tinker - BEZA drpd byCategoryPerBranch() yg dah trim()
 * dalaman). trim() kedua-dua belah setiap perbandingan filter DI SINI, & nilai output ditrim jugak
 * (pattern sama spt GetRearrangeRecommendationsTool/GetStockoutReorderCandidatesTool).
 */
#[Name('get-restock-by-bucket')]
#[Description('Cadangan restock silang kategori x cawangan, dikumpul ikut Saiz atau Berat (bucket) - guna list-restock-categories dulu jika nak tapis category_code.')]
#[IsReadOnly]
class GetRestockByBucketTool extends Tool
{
    public const MAX_RESULTS = 20;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'grain' => ['required', 'string', 'in:size,weight'],
            'period' => ['nullable', 'string', 'in:'.implode(',', array_keys(RestockAnalysisCalculator::PERIOD_LABELS))],
            'category_code' => ['nullable', 'string'],
            'store_code' => ['nullable', 'string'],
            'only_actionable' => ['nullable', 'boolean'],
        ]);

        $grain = $validated['grain'];
        $period = $validated['period'] ?? RestockAnalysisCalculator::DEFAULT_PERIOD;
        $onlyActionable = $validated['only_actionable'] ?? true;
        $categoryCode = null;

        if ($rawCategoryCode = $validated['category_code'] ?? null) {
            $categoryCode = trim($rawCategoryCode);

            if (! Category::where('CategoryCode', $categoryCode)->exists()) {
                return Response::error("Kod kategori \"{$categoryCode}\" tidak wujud - guna list-restock-categories utk dapatkan kod yg sah.");
            }
        }

        $rows = $grain === 'size'
            ? RestockAnalysisCalculator::bySize($period)
            : RestockAnalysisCalculator::byWeight($period);

        if ($categoryCode) {
            $rows = $rows->filter(fn (array $row) => trim((string) $row['category_code']) === $categoryCode);
        }

        if ($storeCode = $validated['store_code'] ?? null) {
            $storeCode = trim($storeCode);
            $rows = $rows->filter(fn (array $row) => trim((string) $row['store_code']) === $storeCode);
        }

        if ($onlyActionable) {
            $rows = $rows->where('verdict', '!=', RestockAnalysisCalculator::VERDICT_OK);
        }

        $totalCount = $rows->count();
        $rows = $rows->take(self::MAX_RESULTS);

        $result = $rows->map(fn (array $row) => [
            'category_code' => trim((string) $row['category_code']),
            'category_name' => $row['category_name'],
            'store_code' => trim((string) $row['store_code']),
            'bucket' => $row['bucket'],
            'pieces_received' => $row['pieces_received'],
            'pieces_sold' => $row['pieces_sold'],
            'current_stock' => $row['current_stock'],
            'velocity_per_month' => $row['velocity_per_month'],
            'target_stock' => $row['target_stock'],
            'gap' => $row['gap'],
            'verdict' => $row['verdict'],
        ])->values()->all();

        return Response::structured([
            'grain' => $grain,
            'period' => $period,
            'total_count' => $totalCount,
            'truncated_count' => max(0, $totalCount - count($result)),
            'rows' => $result,
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'grain' => $schema->string()
                ->description('Bucket ikut "size" (saiz) atau "weight" (berat emas).')
                ->required(),
            'period' => $schema->string()
                ->description('Pilihan - tempoh trend Jualan/Bulan (lalai "3m"). Nilai sah: '.implode(', ', array_keys(RestockAnalysisCalculator::PERIOD_LABELS)).'.'),
            'category_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU kod kategori (cth. "RT") - dapatkan drpd list-restock-categories.'),
            'store_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU cawangan (cth. "PERLING").'),
            'only_actionable' => $schema->boolean()
                ->description('Lalai true - sorok baris "Stok Cukup" (verdict OK), pulangkan hanya bucket yg PERLU tindakan.')
                ->default(true),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'grain' => $schema->string()->required(),
            'period' => $schema->string()->required(),
            'total_count' => $schema->integer()->description('Jumlah baris sepadan sebelum dipangkas.')->required(),
            'truncated_count' => $schema->integer()->description('Bilangan baris disorok kerana melebihi had '.self::MAX_RESULTS.'.')->required(),
            'rows' => $schema->array()->description('Senarai cadangan, susun ikut gap menurun.')->required(),
        ];
    }
}
