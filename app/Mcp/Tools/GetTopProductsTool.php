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

/** Design terlaris (ikut InternalCode) - rujuk SalesAnalytics::topProducts(). */
#[Name('get-top-products')]
#[Description('Design (InternalCode) terlaris dlm tempoh, disusun ikut kuantiti, hasil atau margin. Baris tanpa InternalCode (cth. token/misc) dikecualikan. Maks 50 baris, julat 366 hari.')]
#[IsReadOnly]
class GetTopProductsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'store_code' => ['nullable', 'string'],
            'category_code' => ['nullable', 'string'],
            'order_by' => ['nullable', 'in:qty,revenue,margin'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SalesAnalytics::MAX_ROWS],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $orderBy = $validated['order_by'] ?? 'qty';

        try {
            $rows = (new SalesAnalytics)->topProducts(
                $dateFrom,
                $dateTo,
                $validated['store_code'] ?? null,
                $validated['category_code'] ?? null,
                $orderBy,
                (int) ($validated['limit'] ?? 10),
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return Response::error('Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi, atau kecilkan julat tarikh.');
        }

        return Response::structured([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'order_by' => $orderBy,
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
            'store_code' => $schema->string()->description('Pilihan - hadkan kpd SATU cawangan.'),
            'category_code' => $schema->string()->description('Pilihan - hadkan kpd satu kod kategori (cth. "RT").'),
            'order_by' => $schema->string()->description('qty (lalai) | revenue | margin.'),
            'limit' => $schema->integer()->description('Bilangan baris, lalai 10, maks 50.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'order_by' => $schema->string()->required(),
            'rows' => $schema->array()->description('Setiap baris: internal_code, category_code, qty, revenue, gross_margin, margin_pct.')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
