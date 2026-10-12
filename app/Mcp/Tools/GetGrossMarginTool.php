<?php

namespace App\Mcp\Tools;

use App\Support\SalesAnalytics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Margin kasar + jualan ikut kategori/cawangan (satu tool utk dua keperluan: "jualan ikut
 * kategori" Marketing & "margin" Finance - elak tool pertindihan). Rujuk SalesAnalytics.
 */
#[Name('get-gross-margin')]
#[Description('Jualan, kos & margin kasar (RM dan %) ikut kategori atau cawangan, dgn peratus sumbangan jualan. Hanya baris jualan (SI); pemulangan tak dikira. lines_without_cost = baris berkos 0 yg menaikkan margin secara palsu. Maks 50 baris, julat 366 hari.')]
#[IsReadOnly]
class GetGrossMarginTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'store_code' => ['nullable', 'string'],
            'group_by' => ['nullable', 'in:category,store'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $groupBy = $validated['group_by'] ?? 'category';

        try {
            $rows = (new SalesAnalytics)->grossMargin($dateFrom, $dateTo, $validated['store_code'] ?? null, $groupBy);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return Response::error('Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi, atau kecilkan julat tarikh.');
        }

        $revenue = array_sum(array_column($rows, 'revenue'));
        $margin = array_sum(array_column($rows, 'gross_margin'));

        return Response::structured([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'group_by' => $groupBy,
            'total_revenue' => round($revenue, 2),
            'total_gross_margin' => round($margin, 2),
            'overall_margin_pct' => $revenue > 0 ? round($margin / $revenue * 100, 2) : null,
            'rows' => $rows,
            'data_as_of' => now()->toDateTimeString(),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Tarikh mula (YYYY-MM-DD) - lalai awal bulan semasa.'),
            'date_to' => $schema->string()->description('Tarikh akhir (YYYY-MM-DD) - lalai hari ini.'),
            'store_code' => $schema->string()->description('Pilihan - hadkan kpd SATU cawangan. Kosong = semua.'),
            'group_by' => $schema->string()->description('category (lalai) | store.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'group_by' => $schema->string()->required(),
            'total_revenue' => $schema->number()->description('Jumlah hasil (RM) bagi baris yg dipulangkan (maks 50 teratas).')->required(),
            'total_gross_margin' => $schema->number()->required(),
            'overall_margin_pct' => $schema->number()->description('Margin kasar % keseluruhan, null jika hasil 0.'),
            'rows' => $schema->array()->description('Setiap baris: key, label, revenue, cost, gross_margin, margin_pct, revenue_share_pct, qty, lines_without_cost.')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
