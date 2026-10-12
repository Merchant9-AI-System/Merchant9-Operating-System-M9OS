<?php

namespace App\Mcp\Tools;

use App\Support\SalesAnalytics;
use App\Support\VendorSpendAnalytics;
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

/** Perbelanjaan pembekal drpd invois pembelian FINM9 - rujuk VendorSpendAnalytics. */
#[Name('get-vendor-spend')]
#[Description('Perbelanjaan pembekal (invois pembelian FINM9) dlm tempoh, disusun ikut jumlah posted menurun. posted = sudah posting ke lejar; unposted = belum (dilaporkan berasingan). BUKAN baki hutang tertunggak. Maks 50 pembekal, julat 366 hari.')]
#[IsReadOnly]
class GetVendorSpendTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'vendor_code' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.VendorSpendAnalytics::MAX_ROWS],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        try {
            $result = (new VendorSpendAnalytics)->spend($dateFrom, $dateTo, $validated['vendor_code'] ?? null, (int) ($validated['limit'] ?? 10));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return Response::error('Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi, atau kecilkan julat tarikh.');
        }

        return Response::structured([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'posted_total' => $result['posted_total'],
            'unposted_total' => $result['unposted_total'],
            'vendors' => $result['vendors'],
            'data_as_of' => now()->toDateTimeString(),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Tarikh mula (YYYY-MM-DD) - lalai awal bulan semasa.'),
            'date_to' => $schema->string()->description('Tarikh akhir (YYYY-MM-DD) - lalai hari ini.'),
            'vendor_code' => $schema->string()->description('Pilihan - hadkan kpd SATU kod pembekal.'),
            'limit' => $schema->integer()->description('Bilangan pembekal, lalai 10, maks '.SalesAnalytics::MAX_ROWS.'.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'posted_total' => $schema->number()->description('Jumlah invois posted bagi pembekal yg dipulangkan (RM).')->required(),
            'unposted_total' => $schema->number()->description('Jumlah invois belum posting bagi pembekal yg dipulangkan (RM).')->required(),
            'vendors' => $schema->array()->description('Setiap baris: vendor_code, vendor_name, posted_total, posted_invoices, unposted_total, unposted_invoices.')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
