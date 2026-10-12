<?php

namespace App\Mcp\Servers;

use App\Enums\InsightAgent;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Server Insights diskop kpd peranan Ceo (rujuk RoleInsightsServer & config/insights_roles.php).
 * Local/stdio sahaja: `php artisan mcp:start insights-ceo`.
 */
#[Name('Business Insights - CEO')]
#[Version('0.1.0')]
#[Instructions('Agent CEO Merchant9: TIDAK query pangkalan data. Baca insight semua peranan (get-previous-insights) lalu cari konflik, kebergantungan dan keutamaan, kemudian tulis insight CEO (write-insight, atas nama CEO sahaja). Setiap insight mesti ada figures daripada insight yang dibaca, sources dan data_as_of.')]
class CeoInsightsServer extends RoleInsightsServer
{
    protected function agent(): InsightAgent
    {
        return InsightAgent::Ceo;
    }
}
