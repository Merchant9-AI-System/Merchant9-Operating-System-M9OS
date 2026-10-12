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
 * Ringkasan jualan JEMiSys ikut cawangan/hari/bulan/jumlah. Peraturan jualan sah (batal
 * dikecualikan, pemulangan berasingan) - rujuk SalesAnalytics. Query LIVE ke 'jemisys'.
 */
#[Name('get-sales-summary')]
#[Description('Ringkasan jualan (invois sah, tanpa yang batal) ikut cawangan, hari, bulan atau jumlah keseluruhan. metric: amount (RM, lalai), pieces (BILANGAN PIECE terjual - baris design sahaja, pemulangan tak ditolak) atau both. Pemulangan (SR) dilaporkan berasingan dlm returns_net. Julat tarikh maksimum 366 hari.')]
#[IsReadOnly]
class GetSalesSummaryTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'store_code' => ['nullable', 'string'],
            'group_by' => ['nullable', 'in:store,day,month,total'],
            'metric' => ['nullable', 'in:amount,pieces,both'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $groupBy = $validated['group_by'] ?? 'store';
        $metric = $validated['metric'] ?? 'amount';
        $storeCode = $validated['store_code'] ?? null;

        try {
            $analytics = new SalesAnalytics;
            $amountRows = $metric === 'pieces' ? [] : $analytics->summary($dateFrom, $dateTo, $storeCode, $groupBy);
            $pieceRows = $metric === 'amount' ? [] : $analytics->pieces($dateFrom, $dateTo, $storeCode, $groupBy);
            $rows = match ($metric) {
                'amount' => $amountRows,
                'pieces' => $pieceRows,
                default => SalesAnalytics::mergeByPeriod($amountRows, $pieceRows, $groupBy),
            };
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return Response::error('Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi, atau kecilkan julat tarikh.');
        }

        return Response::structured([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'group_by' => $groupBy,
            'metric' => $metric,
            ...($metric === 'pieces' ? [] : [
                'total_net_sales' => round(array_sum(array_column($rows, 'net_sales')), 2),
                'total_invoices' => array_sum(array_column($rows, 'invoice_count')),
            ]),
            ...($metric === 'amount' ? [] : [
                'total_pieces' => array_sum(array_column($rows, 'pieces_sold')),
                'total_non_design_qty' => array_sum(array_column($rows, 'non_design_qty')),
            ]),
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
            'store_code' => $schema->string()->description('Pilihan - hadkan kpd SATU cawangan (cth. "TTDI"). Kosong = semua.'),
            'group_by' => $schema->string()->description('store (lalai) | day | month | total.'),
            'metric' => $schema->string()->description('amount (lalai, RM) | pieces (bilangan piece terjual) | both.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'group_by' => $schema->string()->required(),
            'metric' => $schema->string()->description('amount | pieces | both.')->required(),
            'total_net_sales' => $schema->number()->description('Jualan bersih (SI + SR negatif) dlm RM. Tiada bila metric=pieces.'),
            'total_invoices' => $schema->integer()->description('Bilangan invois jualan (SI) sah. Tiada bila metric=pieces.'),
            'total_pieces' => $schema->integer()->description('Jumlah piece design terjual (jualan SI, pemulangan tak ditolak). Tiada bila metric=amount.'),
            'total_non_design_qty' => $schema->integer()->description('Qty baris tanpa kod design (token/misc), bukan piece jewelry. Tiada bila metric=amount.'),
            'rows' => $schema->array()->description('Setiap baris: period; amount: sales_net, returns_net, net_sales, invoice_count, return_count; pieces: pieces_sold, non_design_qty.')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
