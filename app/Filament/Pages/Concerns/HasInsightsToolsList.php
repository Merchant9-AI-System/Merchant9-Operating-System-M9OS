<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\InsightAgent;
use App\Mcp\Servers\InsightsServer;
use ReflectionClass;

/**
 * Dikongsi oleh halaman "API Docs" yg perlu senarai tool server Business Insights (InsightsServer) BENAR2 wujud -
 * dibaca terus drpd definisi server & config/insights_roles.php (sama sumber dgn server sebenar), bukan senarai
 * statik. Insights LOCAL (stdio) sahaja - tiada URL web / bearer token, jadi halaman docs hanya mendokumenkan
 * (tiada "Try it out").
 */
trait HasInsightsToolsList
{
    /**
     * Rujuk HasInventoryToolsList::getInventoryTools() - newInstanceWithoutConstructor() kerana Transport hanya wujud
     * dlm konteks permintaan MCP sebenar.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function getInsightsTools(): array
    {
        $server = (new ReflectionClass(InsightsServer::class))->newInstanceWithoutConstructor();

        return $server->createContext()->tools()
            ->map(fn ($tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->toArray()['inputSchema'] ?? ['properties' => (object) [], 'required' => []],
            ])
            ->values()
            ->all();
    }

    /**
     * Server Insights ikut peranan (RoleInsightsServer): handle `php artisan mcp:start`, peranan & tool yg didedahkan
     * (tool data drpd config/insights_roles.php + dua tool insight yg dikunci kpd peranan itu).
     *
     * @return array<int, array{handle: string, role: string, label: string, tools: array<int, string>}>
     */
    public function getInsightsRoleServers(): array
    {
        return collect([InsightAgent::Procurement, InsightAgent::Finance, InsightAgent::Marketing, InsightAgent::Ceo])
            ->map(fn (InsightAgent $agent) => [
                'handle' => 'insights-'.$agent->value,
                'role' => $agent->value,
                'label' => $agent->label(),
                'tools' => [
                    ...array_map(fn (string $toolClass) => app($toolClass)->name(), config("insights_roles.{$agent->value}", [])),
                    'get-previous-insights',
                    'write-insight',
                ],
            ])
            ->all();
    }
}
