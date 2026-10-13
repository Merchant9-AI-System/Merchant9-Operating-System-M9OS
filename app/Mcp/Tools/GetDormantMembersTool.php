<?php

namespace App\Mcp\Tools;

use App\Support\MemberDormancyAnalytics;
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
 * Ahli dormant (lama tak membeli) - jalur masa sejak pembelian terakhir, ahli bernilai tinggi yg dormant, ikut
 * cawangan pendaftaran & jenis ahli. Agregat sahaja - rujuk MemberDormancyAnalytics.
 */
#[Name('get-dormant-members')]
#[Description('Ahli yang lama tak membeli, berdasarkan pembelian TERAKHIR dalam 366 hari sehingga as_of: jalur Aktif (0-30), Sejuk (31-90), Dormant (91-180) dan Lapsed (181-366 hari) dgn bilangan & belanja lalu; bilangan ahli dormant (lebih drpd inactive_days), ahli bernilai tinggi (belanja >= RM7,000) yg dormant, serta ahli berdaftar tanpa pembelian dlm 366 hari. Pecahan ikut cawangan pendaftaran & jenis ahli. Agregat sahaja - nombor ahli dan butiran peribadi TIDAK dipulangkan.')]
#[IsReadOnly]
class GetDormantMembersTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'inactive_days' => ['nullable', 'integer', 'min:30', 'max:365'],
        ]);

        $asOf = Carbon::parse($validated['as_of'] ?? now())->toDateString();
        $inactiveDays = (int) ($validated['inactive_days'] ?? 90);

        if (Carbon::parse($asOf)->isFuture()) {
            return Response::error('as_of tidak boleh pada masa depan.');
        }

        try {
            $summary = (new MemberDormancyAnalytics)->summary($asOf, $inactiveDays);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return Response::error('Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi, atau kecilkan julat tarikh.');
        }

        return Response::structured([
            ...$summary,
            'data_as_of' => now()->toDateTimeString(),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()->description('Tarikh rujukan (YYYY-MM-DD) - lalai hari ini. Tetingkap pembelian ialah 366 hari sebelum tarikh ini.'),
            'inactive_days' => $schema->integer()->description('Ahli dikira dormant bila pembelian terakhir lebih lama drpd N hari (30-365, lalai 90).'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()->required(),
            'window_from' => $schema->string()->description('Awal tetingkap pembelian (366 hari sebelum as_of).')->required(),
            'inactive_days' => $schema->integer()->required(),
            'registered_members' => $schema->integer()->description('Ahli berdaftar yg tak dibatalkan sehingga as_of.')->required(),
            'purchasers_in_window' => $schema->integer()->description('Ahli yg membeli sekurang-kurangnya sekali dlm tetingkap.')->required(),
            'no_purchase_in_window' => $schema->integer()->description('Berdaftar tapi TIADA pembelian dlm tetingkap (tak pernah membeli ATAU lebih 366 hari lalu - tak boleh dibezakan).')->required(),
            'no_purchase_in_window_pct' => $schema->number()->description('% drpd ahli berdaftar.'),
            'dormant_members' => $schema->integer()->description('Ahli yg ADA membeli dlm tetingkap tetapi pembelian terakhir lebih drpd inactive_days lalu.')->required(),
            'dormant_window_spend_rm' => $schema->number()->description('Jumlah belanja lalu (dlm tetingkap) ahli dormant, RM.')->required(),
            'high_value_dormant' => $schema->object()->description('Ahli dormant dgn belanja dlm tetingkap >= min_window_spend_rm: members, window_spend_rm.')->required(),
            'buckets' => $schema->array()->description('Jalur sejak pembelian terakhir: label, days_min, days_max, members, share_of_registered_pct, window_spend_rm, avg_spend_rm.')->required(),
            'by_registration_store' => $schema->array()->description('Ikut cawangan PENDAFTARAN: store_code, registered, purchasers_in_window, active_30d, dormant, no_purchase_in_window.')->required(),
            'by_member_type' => $schema->array()->description('Ikut jenis ahli: member_type, registered, purchasers_in_window, dormant, no_purchase_in_window.')->required(),
            'truncated' => $schema->boolean()->description('true = keputusan dipotong kerana had baris.')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
