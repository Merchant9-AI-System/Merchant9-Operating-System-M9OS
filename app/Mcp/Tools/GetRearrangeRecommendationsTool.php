<?php

namespace App\Mcp\Tools;

use App\Support\StockRearrangementRecommender;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Cadangan pindah stok antara cawangan (SATU pasangan donor->receiver per design) - sumber
 * data & penapisan SAMA dgn App\Filament\Pages\StockRearrangementRecommendation
 * (StockRearrangementRecommender::recommendations(), rujuk dokblok kelas tsb utk rule penuh).
 * BUKAN App\Support\RearrangeCalculator (algoritma greedy multi-donor/multi-receiver di page
 * Rearrange - kekal berasingan, TIDAK didedahkan sbg tool di sini).
 *
 * Keputusan cache via rememberForever (rujuk StockRearrangementRecommender) - tiada TTL, data
 * boleh lapuk sehingga proses sync seterusnya bersihkan cache tsb.
 *
 * #[Name(...)] WAJIB - rujuk nota sama di ListRestockCategoriesTool.
 */
#[Name('get-rearrange-recommendations')]
#[Description('Cadangan pindah 1 unit stok drpd cawangan yg ada stok (>=3) ke cawangan yg sold out (stok=0) bagi design sama - guna list-restock-categories/get-restock-suggestions dulu kalau perlukan kod kategori. Data mungkin lapuk sehingga sync seterusnya (cache tanpa TTL).')]
#[IsReadOnly]
class GetRearrangeRecommendationsTool extends Tool
{
    public const MAX_RESULTS = 20;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'from_branch' => ['nullable', 'string'],
            'to_branch' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:High,Medium,Low'],
            'exclude_branches' => ['nullable', 'array'],
            'exclude_branches.*' => ['string'],
            'search' => ['nullable', 'string'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $offset = $validated['offset'] ?? 0;

        $rows = StockRearrangementRecommender::recommendations();

        // trim() - 'from_branch'/'to_branch' datang drpd StoreCode, lajur CHAR terpadding
        // (cth. "SGBESI" + 14 ruang = 20 aksara, disahkan sebenar via tinker) - padanan exact
        // tanpa trim() GAGAL senyap utk pemanggil luar (agen AI x akan tahu bilangan ruang
        // tepat). strtolower() jugak - sama isu case StoreCode spt InventoryPiece::
        // scopePhysicalStore().
        if ($fromBranch = $validated['from_branch'] ?? null) {
            $needle = mb_strtolower(trim($fromBranch));
            $rows = $rows->filter(fn (array $r) => mb_strtolower(trim($r['from_branch'])) === $needle);
        }

        if ($toBranch = $validated['to_branch'] ?? null) {
            $needle = mb_strtolower(trim($toBranch));
            $rows = $rows->filter(fn (array $r) => mb_strtolower(trim($r['to_branch'])) === $needle);
        }

        if ($priority = $validated['priority'] ?? null) {
            $rows = $rows->where('priority', $priority);
        }

        if (filled($excludeBranches = $validated['exclude_branches'] ?? [])) {
            $excludeBranchesLower = array_map(fn (string $b) => mb_strtolower(trim($b)), $excludeBranches);
            $rows = $rows->reject(fn (array $r) => in_array(mb_strtolower(trim($r['from_branch'])), $excludeBranchesLower)
                || in_array(mb_strtolower(trim($r['to_branch'])), $excludeBranchesLower));
        }

        if ($search = $validated['search'] ?? null) {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn (array $r) => str_contains(mb_strtolower((string) $r['internal_code']), $needle)
                || str_contains(mb_strtolower((string) $r['item_desc']), $needle));
        }

        $totalCount = $rows->count();

        // trim() from_branch/to_branch drpd baris DIPULANGKAN (bukan reason/suggestion/
        // receiver_label - teks bebas dibina terus dlm StockRearrangementRecommender, kekal
        // asal drpd sumber sedia ada, elak sentuh kelas dikongsi page sebenar).
        $recommendations = $rows->slice($offset, self::MAX_RESULTS)
            ->map(fn (array $r) => [...$r, 'from_branch' => trim($r['from_branch']), 'to_branch' => trim($r['to_branch'])])
            ->values()
            ->all();

        return Response::structured([
            'total_count' => $totalCount,
            'offset' => $offset,
            'truncated_count' => max(0, $totalCount - $offset - count($recommendations)),
            'recommendations' => $recommendations,
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from_branch' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU cawangan asal (cth. "PERLING").'),
            'to_branch' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU cawangan destinasi (cth. "DAMAI").'),
            'priority' => $schema->string()
                ->description('Pilihan - "High", "Medium", atau "Low" sahaja.'),
            'exclude_branches' => $schema->array()
                ->description('Pilihan - senarai kod cawangan utk dikecualikan (cth. ["HQ", "SECURITY"]).'),
            'search' => $schema->string()
                ->description('Pilihan - carian teks bebas atas kod design atau jenis item.'),
            'offset' => $schema->integer()
                ->description('Pilihan - langkau seberapa banyak baris (halaman seterusnya) - lalai 0. Cth. offset=20 utk baris 21-40.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'total_count' => $schema->integer()->description('Jumlah baris sepadan sebelum dipangkas.')->required(),
            'offset' => $schema->integer()->description('Offset yg dipakai.')->required(),
            'truncated_count' => $schema->integer()->description('Bilangan baris disorok kerana melebihi had '.self::MAX_RESULTS.' selepas offset.')->required(),
            'recommendations' => $schema->array()->description('Senarai cadangan pindah stok.')->required(),
        ];
    }
}
