<?php

use App\Mcp\Servers\RestockServer;
use Laravel\Mcp\Facades\Mcp;

// Modul 1 (M9OS Integration Plan) - local/stdio utk Claude Code/Desktop/Inspector di
// mesin ni (`php artisan mcp:start restock`), TIADA auth (proses tempatan sahaja).
Mcp::local('restock', RestockServer::class);

// Laluan web SAMA (RestockServer, tools SAMA) utk Claude.ai Custom Connectors (URL
// boleh-tampal) - disahkan modul local dah berfungsi dulu sblm buka laluan ni. WAJIB
// auth:sanctum (Bearer token, rujuk App\Models\User HasApiTokens) - data restock
// sebenar, x boleh terdedah tanpa token. throttle:mcp - rujuk AppServiceProvider::boot().
Mcp::web('/mcp/restock', RestockServer::class)->middleware(['throttle:mcp', 'auth:sanctum']);
