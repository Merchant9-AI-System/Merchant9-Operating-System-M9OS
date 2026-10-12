<?php

namespace App\Mcp\Tools;

use App\Support\GiftTierAnalytics;
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
 * Ringkasan Free Gift - Customer: bilangan customer berahli setiap tier hadiah, ikut cawangan utama,
 * serta anggaran unit barang hadiah. Logik & peraturan: rujuk GiftTierAnalytics. Query LIVE ke 'jemisys'.
 */
#[Name('get-gift-tier-summary')]
#[Description('Free Gift - Customer: bilangan customer berahli (VIPNo) bagi setiap tier hadiah berdasarkan JUMLAH belian dalam tempoh, dipecah ikut cawangan utama (cawangan belian terbesar customer), serta anggaran unit setiap barang hadiah (utk Procurement). Hanya invois jualan sah (SI); nombor ahli tidak dipulangkan. Julat tarikh maksimum 366 hari.')]
#[IsReadOnly]
class GetGiftTierSummaryTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        try {
            $summary = (new GiftTierAnalytics)->summary($dateFrom, $dateTo);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return Response::error('Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi, atau kecilkan julat tarikh.');
        }

        return Response::structured([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            ...$summary,
            'data_as_of' => now()->toDateTimeString(),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Tarikh mula (YYYY-MM-DD) - lalai awal bulan semasa.'),
            'date_to' => $schema->string()->description('Tarikh akhir (YYYY-MM-DD) - lalai hari ini.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'total_customers' => $schema->integer()->description('Bilangan customer berahli yg membeli dlm tempoh.')->required(),
            'multi_branch_customers' => $schema->integer()->description('Customer yg membeli di lebih satu cawangan (dikira di cawangan utama sahaja).')->required(),
            'tiers' => $schema->array()->description('Setiap tier: gift, min_purchase_rm, max_purchase_rm (null = tanpa had), customers, by_branch (cawangan utama => bilangan).')->required(),
            'branches' => $schema->array()->description('Senarai cawangan utama yg muncul.')->required(),
            'gift_units' => $schema->array()->description('Anggaran unit setiap barang hadiah (1 unit/customer dlm tier): item, units. Tidak termasuk tier pilihan.')->required(),
            'choice_gifts' => $schema->array()->description('Tier di mana customer PILIH SALAH SATU barang (tak boleh dikira tepat): label, options, customers.')->required(),
            'truncated' => $schema->boolean()->description('true = keputusan dipotong kerana had baris - kecilkan julat tarikh.')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
