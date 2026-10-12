<?php

namespace App\Mcp\Tools;

use App\Enums\InsightAgent;
use App\Enums\InsightSeverity;
use App\Models\Insight;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * Satu-satunya tool TULIS dlm server ni - menulis ke jadual `insights` app sendiri (bukan
 * JEMiSys/FINM9, yg kekal baca sahaja). `sources` wajib supaya setiap insight boleh disahkan
 * manusia; `figures` ialah angka drpd tool, bukan hasil kiraan agent sendiri.
 * Jika `dedupe_key` diberi & insight agent yg sama dgn kunci sama wujud dlm 24 jam, ia
 * DIKEMAS KINI (bukan duplikat baru).
 */
#[Name('write-insight')]
#[Description('Simpan satu insight berstruktur utk dipaparkan di dashboard. Wajib sertakan figures (angka drpd tool lain, jangan dikira/direka sendiri), sources (nama tool + parameter yg menghasilkan angka) & data_as_of. Guna dedupe_key stabil (cth. "procurement:stok-rendah:CE") utk elak insight sama diulang.')]
#[IsIdempotent]
class WriteInsightTool extends Tool
{
    /**
     * @param  InsightAgent|null  $lockedAgent  Server berskop peranan (RoleInsightsServer) mengunci tool ni kpd SATU
     *                                          peranan - `agent` dibuang drpd skema & percubaan menulis atas nama
     *                                          peranan lain ditolak. null = server penuh (agent wajib diberi).
     */
    public function __construct(private readonly ?InsightAgent $lockedAgent = null) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->lockedAgent && $request->get('agent') !== null && $request->get('agent') !== $this->lockedAgent->value) {
            return Response::error("Server ini dikunci kpd agent '{$this->lockedAgent->value}' - tak boleh menulis insight atas nama '{$request->get('agent')}'.");
        }

        $validated = $request->validate([
            ...($this->lockedAgent ? [] : ['agent' => ['required', Rule::enum(InsightAgent::class)]]),
            'severity' => ['required', Rule::enum(InsightSeverity::class)],
            'title' => ['required', 'string', 'max:150'],
            'summary' => ['required', 'string', 'max:2000'],
            'figures' => ['required', 'array', 'min:1'],
            'recommendation' => ['nullable', 'string', 'max:2000'],
            'sources' => ['required', 'array', 'min:1'],
            'sources.*' => ['string', 'max:300'],
            'data_as_of' => ['required', 'date'],
            'dedupe_key' => ['nullable', 'string', 'max:120'],
        ]);

        if ($this->lockedAgent) {
            $validated['agent'] = $this->lockedAgent->value;
        }

        $existing = isset($validated['dedupe_key'])
            ? Insight::query()
                ->where('agent', $validated['agent'])
                ->where('dedupe_key', $validated['dedupe_key'])
                ->where('created_at', '>=', now()->subDay())
                ->latest()
                ->first()
            : null;

        $insight = $existing ?? new Insight;
        $insight->fill($validated)->save();

        return Response::structured([
            'id' => $insight->id,
            'action' => $existing ? 'updated' : 'created',
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...($this->lockedAgent ? [] : ['agent' => $schema->string()->description('procurement | finance | marketing | media | ceo.')->required()]),
            'severity' => $schema->string()->description('high | medium | info.')->required(),
            'title' => $schema->string()->description('Tajuk ringkas (maks 150 aksara).')->required(),
            'summary' => $schema->string()->description('1-2 ayat penerangan.')->required(),
            'figures' => $schema->object()->description('Angka rujukan drpd tool (cth. {"jualan_sep": 23064000}). Mesti ada sekurang-kurangnya satu.')->required(),
            'recommendation' => $schema->string()->description('Cadangan tindakan (pilihan).'),
            'sources' => $schema->array()->items($schema->string())->description('Senarai tool+parameter yg menghasilkan angka (cth. "get-sales-summary 2026-09-01..2026-09-30").')->required(),
            'data_as_of' => $schema->string()->description('Tarikh/masa data sumber (YYYY-MM-DD atau datetime).')->required(),
            'dedupe_key' => $schema->string()->description('Kunci stabil utk elak duplikat dlm 24 jam (pilihan).'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'action' => $schema->string()->description('created | updated.')->required(),
        ];
    }
}
