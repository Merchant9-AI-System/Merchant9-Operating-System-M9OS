<?php

namespace App\Mcp\Tools;

use App\Support\GoldRateAnalytics;
use Carbon\Carbon;
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
 * Trend harga emas (kadar beli/jual kedai & kos semasa, RM per gram) ikut kelas ketulenan - rujuk GoldRateAnalytics.
 */
#[Name('get-gold-rate-trend')]
#[Description('Trend harga emas Merchant9 (RM per gram) ikut kelas ketulenan (cth. 916, 999, 750): kadar BELI kedai, kadar JUAL, dan KOS semasa; penutup setiap hari/minggu/bulan (kemas kini terakhir tempoh itu), julat rendah-tinggi sepanjang tempoh, bilangan kemas kini, beza kos-beli, serta ringkasan perubahan (RM dan %) merentas tempoh. Kadar jual 0 bermaksud tiada kadar jual ditetapkan (dipulangkan null). group_by=day hanya untuk julat sehingga 92 hari; maks 5 kelas; julat maksimum 366 hari.')]
#[IsReadOnly]
class GetGoldRateTrendTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'class_codes' => ['nullable', 'array', 'max:'.GoldRateAnalytics::MAX_CLASSES],
            'class_codes.*' => ['string', 'max:20'],
            'store_code' => ['nullable', 'string'],
            'group_by' => ['nullable', 'in:day,week,month'],
        ]);

        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $dateFrom = $validated['date_from'] ?? Carbon::parse($dateTo)->subDays(29)->toDateString();
        $groupBy = $validated['group_by'] ?? 'day';

        try {
            $trend = (new GoldRateAnalytics)->trend(
                $dateFrom,
                $dateTo,
                $validated['class_codes'] ?? ['916'],
                $validated['store_code'] ?? null,
                $groupBy,
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
            ...$trend,
            'data_as_of' => now()->toDateTimeString(),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Tarikh mula (YYYY-MM-DD) - lalai 30 hari sebelum date_to.'),
            'date_to' => $schema->string()->description('Tarikh akhir (YYYY-MM-DD) - lalai hari ini.'),
            'class_codes' => $schema->array()->items($schema->string())->description('Kelas ketulenan, maks 5 (lalai ["916"]). Kod yg dijumpai dlm data: 375, 585, 750, 916, 916B, 916Ptg, 950, 999, 9999, PAUN, "PERAK 925", "SILVER 999".'),
            'store_code' => $schema->string()->description('Pilihan - hadkan kpd SATU cawangan (cth. "TTDI"). Kosong = semua cawangan digabung (penutup = kemas kini terlewat merentas cawangan).'),
            'group_by' => $schema->string()->description('day (lalai, julat <= 92 hari) | week (mula Isnin) | month.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'class_codes' => $schema->array()->required(),
            'group_by' => $schema->string()->required(),
            'classes' => $schema->array()->description('Setiap kelas: class_code, points (period, days_with_rates, updates, buy_close, sell_close, cost_close, buy_low, buy_high, cost_low, cost_high, cost_minus_buy; semua RM/gram, null = tiada kadar) dan summary (first_period, last_period, buy/cost: first, last, change, change_pct; buy_period_high/low, cost_period_high/low).')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
