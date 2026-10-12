<?php

namespace App\Mcp\Servers;

use App\Enums\InsightAgent;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Server Insights diskop kpd peranan Marketing (rujuk RoleInsightsServer & config/insights_roles.php).
 * Local/stdio sahaja: `php artisan mcp:start insights-marketing`.
 */
#[Name('Business Insights - Marketing')]
#[Version('0.1.0')]
#[Instructions('Agent Marketing Merchant9: jualan, design terlaris, hadiah customer, aktiviti ahli dan ahli dormant. Semua data ahli ialah agregat - nombor ahli tak tersedia. Sentiasa panggil tool data utk angka - JANGAN kira atau reka angka sendiri. Semak get-previous-insights sebelum write-insight. Insight anda ditulis atas nama Marketing sahaja dan mesti ada sources dan data_as_of.')]
class MarketingInsightsServer extends RoleInsightsServer
{
    protected function agent(): InsightAgent
    {
        return InsightAgent::Marketing;
    }
}
