<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetDormantMembersTool;
use App\Mcp\Tools\GetGiftTierSummaryTool;
use App\Mcp\Tools\GetGoldRateTrendTool;
use App\Mcp\Tools\GetGrossMarginTool;
use App\Mcp\Tools\GetMemberActivityTool;
use App\Mcp\Tools\GetPreviousInsightsTool;
use App\Mcp\Tools\GetSalesSummaryTool;
use App\Mcp\Tools\GetTopProductsTool;
use App\Mcp\Tools\GetVendorSpendTool;
use App\Mcp\Tools\WriteInsightTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Server MCP utk agent AI Merchant9 (rujuk AI_AGENTS_PLAN.md) - Fasa 1: jualan JEMiSys, perbelanjaan
 * pembekal FINM9, & baca/tulis insight. Semua tool data BACA SAHAJA dgn tapisan tarikh wajib +
 * had baris + had masa query; hanya write-insight menulis (ke jadual `insights` app sendiri).
 * Endpoint /mcp/insights (rujuk routes/ai.php). Dipisahkan drpd InventoryServer; Fasa 2+ akan
 * dipecah ikut peranan agent (skop tool terhad per agent).
 */
#[Name('Business Insights')]
#[Version('0.1.0')]
#[Instructions('Data jualan (JEMiSys) & perbelanjaan pembekal (FINM9) Merchant9, serta simpanan insight. Sentiasa panggil tool data utk dapatkan angka - JANGAN kira atau reka angka sendiri. Semak get-previous-insights sebelum write-insight utk elak duplikat. Setiap insight wajib sertakan sources (tool+parameter) dan data_as_of.')]
class InsightsServer extends Server
{
    protected array $tools = [
        GetSalesSummaryTool::class,
        GetGrossMarginTool::class,
        GetGiftTierSummaryTool::class,
        GetMemberActivityTool::class,
        GetDormantMembersTool::class,
        GetGoldRateTrendTool::class,
        GetTopProductsTool::class,
        GetVendorSpendTool::class,
        GetPreviousInsightsTool::class,
        WriteInsightTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
