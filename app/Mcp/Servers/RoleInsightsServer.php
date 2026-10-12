<?php

namespace App\Mcp\Servers;

use App\Enums\InsightAgent;
use App\Mcp\Tools\GetPreviousInsightsTool;
use App\Mcp\Tools\WriteInsightTool;
use Laravel\Mcp\Server;

/**
 * Server MCP Insights yang DISKOP kpd SATU peranan agent (rujuk AI_AGENTS_PLAN.md): hanya tool data dalam
 * config/insights_roles.php untuk peranan itu, serta write-insight & get-previous-insights yang dikunci
 * kpd peranan itu (agent tak boleh menulis/membaca atas nama peranan lain; CEO sahaja boleh baca semua).
 * Server penuh `InsightsServer` kekal utk admin/pembangun.
 */
abstract class RoleInsightsServer extends Server
{
    abstract protected function agent(): InsightAgent;

    protected function boot(): void
    {
        $agent = $this->agent();

        $this->tools = [
            ...config("insights_roles.{$agent->value}", []),
            // CEO membaca insight SEMUA peranan; peranan lain hanya insight sendiri.
            new GetPreviousInsightsTool($agent === InsightAgent::Ceo ? null : $agent),
            new WriteInsightTool($agent),
        ];
    }
}
