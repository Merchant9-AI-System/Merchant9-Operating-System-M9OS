<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetRestockSuggestionsTool;
use App\Mcp\Tools\ListRestockCategoriesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Modul MCP #1 (rujuk M9OS Integration Plan) - cadangan restock utk cawangan/kategori,
 * sumber data SAMA dgn App\Filament\Pages\RestockByCategory (RestockAnalysisCalculator),
 * BUKAN kira semula/logik berasingan. Local (stdio) sahaja buat masa ni - rujuk routes/ai.php.
 */
#[Name('Restock Intelligence')]
#[Version('0.1.0')]
#[Instructions('Cadangan restock Merchant9 ikut kategori & cawangan - guna list-restock-categories dulu utk dapatkan kod kategori sebelum panggil get-restock-suggestions.')]
class RestockServer extends Server
{
    protected array $tools = [
        ListRestockCategoriesTool::class,
        GetRestockSuggestionsTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
