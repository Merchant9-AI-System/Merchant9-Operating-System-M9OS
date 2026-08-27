<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetRearrangeRecommendationsTool;
use App\Mcp\Tools\GetRestockSuggestionsTool;
use App\Mcp\Tools\GetStockoutReorderCandidatesTool;
use App\Mcp\Tools\ListRestockCategoriesTool;
use App\Mcp\Tools\LookupInventoryPiecesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Server MCP inventori M9OS - asalnya "RestockServer" (rujuk M9OS Integration Plan #1),
 * dinamakan semula + dilanjutkan skop ke seluruh domain inventori (restock, rearrange,
 * stockout reorder, lookup stok) atas arahan eksplisit, bukan hanya restock. Setiap tool
 * kekal panggil sumber data SAMA dgn page Filament asal (RestockAnalysisCalculator/
 * StockRearrangementRecommender/StockoutReorderCandidate/InventoryPiece), TIADA kira semula/
 * logik berasingan. Endpoint /mcp/inventory (rujuk routes/ai.php) - asalnya /mcp/restock.
 */
#[Name('Inventory Intelligence')]
#[Version('0.2.0')]
#[Instructions('Cadangan & carian inventori Merchant9 - restock (kategori/cawangan), rearrange antara cawangan, calon reorder segera (stockout), & carian stok semasa. Guna list-restock-categories dulu utk dapatkan kod kategori sebelum panggil get-restock-suggestions.')]
class InventoryServer extends Server
{
    protected array $tools = [
        ListRestockCategoriesTool::class,
        GetRestockSuggestionsTool::class,
        GetRearrangeRecommendationsTool::class,
        GetStockoutReorderCandidatesTool::class,
        LookupInventoryPiecesTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
