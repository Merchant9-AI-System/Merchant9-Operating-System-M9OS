<?php

namespace App\Mcp\Servers;

use App\Enums\InsightAgent;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Server Insights diskop kpd peranan Procurement (rujuk RoleInsightsServer & config/insights_roles.php).
 * Local/stdio sahaja: `php artisan mcp:start insights-procurement`.
 */
#[Name('Business Insights - Procurement')]
#[Version('0.1.0')]
#[Instructions('Agent Procurement Merchant9: perancangan belian. Gunakan tool data (jualan - guna metric=pieces utk bilangan piece, design terlaris, perbelanjaan pembekal, unit hadiah) utk dapatkan angka - JANGAN kira atau reka angka sendiri. Semak get-previous-insights sebelum write-insight utk elak duplikat. Insight anda ditulis atas nama Procurement sahaja dan mesti ada sources (tool+parameter) dan data_as_of.')]
class ProcurementInsightsServer extends RoleInsightsServer
{
    protected function agent(): InsightAgent
    {
        return InsightAgent::Procurement;
    }
}
