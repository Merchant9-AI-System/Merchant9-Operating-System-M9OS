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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** Baca insight terdahulu - utk elak ulang insight sama & bandingkan dgn semalam/minggu lepas. */
#[Name('get-previous-insights')]
#[Description('Baca insight yang telah disimpan (terbaru dahulu), boleh tapis ikut agent, severity & tempoh. Guna sebelum write-insight utk elak duplikat dan utk lihat perubahan berbanding insight lepas.')]
#[IsReadOnly]
class GetPreviousInsightsTool extends Tool
{
    private const MAX_LIMIT = 50;

    /**
     * @param  InsightAgent|null  $scope  Server berskop peranan hanya membaca insight peranan itu sendiri (CEO: null =
     *                                    semua). `agent` dibuang drpd skema bila diskopkan & peranan lain ditolak.
     */
    public function __construct(private readonly ?InsightAgent $scope = null) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->scope && $request->get('agent') !== null && $request->get('agent') !== $this->scope->value) {
            return Response::error("Server ini hanya boleh membaca insight agent '{$this->scope->value}'.");
        }

        $validated = $request->validate([
            'agent' => ['nullable', Rule::enum(InsightAgent::class)],
            'severity' => ['nullable', Rule::enum(InsightSeverity::class)],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $rows = Insight::query()
            ->when($this->scope?->value ?? ($validated['agent'] ?? null), fn ($q, $agent) => $q->where('agent', $agent))
            ->when($validated['severity'] ?? null, fn ($q, $severity) => $q->where('severity', $severity))
            ->where('created_at', '>=', now()->subDays((int) ($validated['days'] ?? 7)))
            ->latest()
            ->limit((int) ($validated['limit'] ?? 20))
            ->get();

        return Response::structured([
            'count' => $rows->count(),
            'insights' => $rows->map(fn (Insight $i) => [
                'id' => $i->id,
                'agent' => $i->agent->value,
                'severity' => $i->severity->value,
                'title' => $i->title,
                'summary' => $i->summary,
                'figures' => $i->figures,
                'recommendation' => $i->recommendation,
                'sources' => $i->sources,
                'data_as_of' => $i->data_as_of->toDateTimeString(),
                'dedupe_key' => $i->dedupe_key,
                'created_at' => $i->created_at->toDateTimeString(),
            ])->values()->all(),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...($this->scope ? [] : ['agent' => $schema->string()->description('Pilihan: procurement | finance | marketing | media | ceo.')]),
            'severity' => $schema->string()->description('Pilihan: high | medium | info.'),
            'days' => $schema->integer()->description('Berapa hari ke belakang, lalai 7, maks 365.'),
            'limit' => $schema->integer()->description('Bilangan insight, lalai 20, maks '.self::MAX_LIMIT.'.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()->required(),
            'insights' => $schema->array()->description('Setiap insight: id, agent, severity, title, summary, figures, recommendation, sources, data_as_of, dedupe_key, created_at.')->required(),
        ];
    }
}
