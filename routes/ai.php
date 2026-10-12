<?php

use App\Mcp\Servers\CeoInsightsServer;
use App\Mcp\Servers\FinanceInsightsServer;
use App\Mcp\Servers\InsightsServer;
use App\Mcp\Servers\InventoryServer;
use App\Mcp\Servers\MarketingInsightsServer;
use App\Mcp\Servers\ProcurementInsightsServer;
use Laravel\Mcp\Facades\Mcp;

// Modul 1 (M9OS Integration Plan), dilanjutkan skop drpd restock sahaja ke seluruh inventori
// (rujuk InventoryServer dokblok) - local/stdio utk Claude Code/Desktop/Inspector di mesin ni
// (`php artisan mcp:start inventory`), TIADA auth (proses tempatan sahaja).
Mcp::local('inventory', InventoryServer::class);

// Agent AI Merchant9 Fasa 1 (rujuk AI_AGENTS_PLAN.md) - jualan JEMiSys, perbelanjaan pembekal
// FINM9 & insight. LOCAL SAHAJA (`php artisan mcp:start insights`): laluan web (Claude.ai
// connector) SENGAJA belum dibuka sbb mendedahkan data jualan/kewangan ke luar - perlu keputusan
// eksplisit dulu.
Mcp::local('insights', InsightsServer::class);

// Server Insights diskop kpd SATU peranan agent (skop tool: config/insights_roles.php; write-insight &
// get-previous-insights dikunci kpd peranan itu). `insights` di atas = semua tool, utk admin/pembangun.
// Local sahaja; sengaja TIDAK didaftar dlm .mcp.json (fail projek dikongsi) - dipanggil oleh penjalan
// agent masing-masing: `php artisan mcp:start insights-procurement|insights-finance|insights-marketing|insights-ceo`.
Mcp::local('insights-procurement', ProcurementInsightsServer::class);
Mcp::local('insights-finance', FinanceInsightsServer::class);
Mcp::local('insights-marketing', MarketingInsightsServer::class);
Mcp::local('insights-ceo', CeoInsightsServer::class);

// Endpoint discovery/DCR OAuth (.well-known/oauth-*, POST oauth/register) - satu panggilan
// ni daftar SELURUH protokol OAuth 2.1+PKCE+DCR (rujuk vendor/laravel/mcp/src/Server/
// Registrar.php::oauthRoutes() - disahkan baca source terus, BUKAN oAuthRoutes() spt sesetengah
// dokumentasi tulis). Turut auto-daftar scope 'mcp:use' via Registrar::ensureMcpScope() - TIADA
// konfigurasi manual diperlukan. Endpoint /oauth/authorize & /oauth/token sendiri didaftar oleh
// Passport (rujuk `install:api --passport`), BUKAN baris ni.
Mcp::oauthRoutes();

// Laluan web SAMA (InventoryServer, tools SAMA) utk Claude.ai Custom Connectors (URL
// boleh-tampal) - disahkan modul local dah berfungsi dulu sblm buka laluan ni. Path
// /mcp/inventory (asalnya /mcp/restock) - dinamakan semula ikut arahan eksplisit apabila
// skop server dilanjutkan melangkaui restock sahaja.
// auth:sanctum,api - DUA guard, cuba ikut urutan: Sanctum (Bearer token ringkas, rujuk
// App\Models\User HasApiTokens & TokensRelationManager - skrip/curl/integrasi dalaman) DULU,
// jatuh balik ke 'api' (Passport, config/auth.php - token dikeluarkan via aliran OAuth
// kelulusan Claude.ai). SATU URL sahaja, kedua-dua cara auth berfungsi - x perlu laluan
// berasingan. throttle:mcp - rujuk AppServiceProvider::boot().
Mcp::web('/mcp/inventory', InventoryServer::class)->middleware(['throttle:mcp', 'auth:sanctum,api']);
