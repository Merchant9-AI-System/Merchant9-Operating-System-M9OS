<?php

namespace App\Mcp\Tools;

use App\Support\MemberAnalytics;
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
 * Aktiviti ahli: ahli aktif/baharu, kadar beli semula, jalur belanja, jenis ahli, ikut cawangan & mata
 * ganjaran. Agregat sahaja - rujuk MemberAnalytics (privasi: nombor ahli & butiran peribadi tak dipulangkan).
 */
#[Name('get-member-activity')]
#[Description('Aktiviti ahli (customer berahli) dlm tempoh: bilangan ahli aktif (ada belian), ahli baharu didaftar, kadar beli semula (>=2 invois), jalur belanja ahli, pecahan jenis ahli & cawangan, serta mata ganjaran (diperoleh/digunakan/ditebus). Agregat sahaja - nombor ahli dan butiran peribadi TIDAK dipulangkan. Julat tarikh maksimum 366 hari.')]
#[IsReadOnly]
class GetMemberActivityTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'store_code' => ['nullable', 'string'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        try {
            $summary = (new MemberAnalytics)->summary($dateFrom, $dateTo, $validated['store_code'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (QueryException $e) {
            report($e);

            return Response::error('Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi, atau kecilkan julat tarikh.');
        }

        return Response::structured([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'store_code' => $validated['store_code'] ?? null,
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
            'store_code' => $schema->string()->description('Pilihan - hadkan kpd SATU cawangan (cth. "TTDI"). Kosong = semua.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'active_members' => $schema->integer()->description('Ahli berbeza yg membuat sekurang-kurangnya satu pembelian (jualan SI sah).')->required(),
            'new_members_registered' => $schema->integer()->description('Ahli yg DIDAFTAR dlm tempoh (MemberSince), tak semestinya membeli.')->required(),
            'active_members_registered_in_period' => $schema->integer()->description('Antara ahli aktif, berapa yg didaftar dlm tempoh yg sama.')->required(),
            'repeat_members' => $schema->integer()->description('Ahli aktif dgn >=2 invois dlm tempoh (merentas cawangan).')->required(),
            'repeat_rate_pct' => $schema->number()->description('repeat_members / active_members (%), null jika tiada ahli aktif.'),
            'avg_spend_per_active_member_rm' => $schema->number()->description('Purata belanja (RM) seorang ahli aktif.'),
            'spend_bands' => $schema->array()->description('Jalur belanja (RM): band, min_rm, members, share_pct, spend_rm.')->required(),
            'by_member_type' => $schema->array()->description('Ahli aktif ikut jenis: member_type, active_members, spend_rm.')->required(),
            'new_members_by_type' => $schema->array()->description('Ahli didaftar ikut jenis: member_type, members.')->required(),
            'by_store' => $schema->array()->description('Ikut cawangan: store_code, active_members (membeli di cawangan itu; ahli di >1 cawangan dikira di setiap satu), new_members (didaftar di cawangan itu).')->required(),
            'points' => $schema->object()->description('Mata ganjaran dlm tempoh: gained, used, redeemed, used_pct_of_gained.')->required(),
            'truncated' => $schema->boolean()->description('true = keputusan dipotong kerana had baris - kecilkan julat tarikh.')->required(),
            'data_as_of' => $schema->string()->required(),
        ];
    }
}
