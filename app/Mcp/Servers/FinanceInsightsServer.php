<?php

namespace App\Mcp\Servers;

use App\Enums\InsightAgent;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Server Insights diskop kpd peranan Finance (rujuk RoleInsightsServer & config/insights_roles.php).
 * Local/stdio sahaja: `php artisan mcp:start insights-finance`.
 */
#[Name('Business Insights - Finance')]
#[Version('0.1.0')]
#[Instructions('Agent Finance Merchant9: jualan, margin kasar dan perbelanjaan pembekal. Sentiasa panggil tool data utk angka - JANGAN kira atau reka angka sendiri. Semak get-previous-insights sebelum write-insight utk elak duplikat. Insight anda ditulis atas nama Finance sahaja dan mesti ada sources (tool+parameter) dan data_as_of.')]
class FinanceInsightsServer extends RoleInsightsServer
{
    protected function agent(): InsightAgent
    {
        return InsightAgent::Finance;
    }
}
