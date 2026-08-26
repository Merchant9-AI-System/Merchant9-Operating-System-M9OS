<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\RestockServer;
use App\Mcp\Tools\GetRestockSuggestionsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skop diuji di sini: pengesahan kod kategori sahaja - laluan "happy path" (baris cadangan
 * sebenar) TIDAK diuji dgn fixture PHPUnit di sini sbb jemisys_inventory_mirror ada ~95 lajur
 * NOT NULL (mencerminkan TblInventory Jemisys sebenar, rujuk migration) - fixture sintetik
 * utk kesemuanya lebih berisiko (teka nilai domain gold-weight/harga yg x difahami sepenuhnya)
 * drpd bernilai. Disahkan sebaliknya via Tinker terus atas data sebenar (rujuk M9OS Integration
 * Plan verification notes) - RestockAnalysisCalculator::byCategoryPerBranch() itu sendiri turut
 * SUDAH dikongsi & diuji tak langsung setiap kali RestockByCategory (Filament) dimuatkan.
 */
class GetRestockSuggestionsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_an_unknown_category_code(): void
    {
        $response = RestockServer::tool(GetRestockSuggestionsTool::class, [
            'category_code' => 'ZZZ-TIDAK-WUJUD',
        ]);

        $response->assertHasErrors()->assertSee('tidak wujud');
    }

    public function test_it_requires_a_category_code(): void
    {
        $response = RestockServer::tool(GetRestockSuggestionsTool::class, []);

        $response->assertHasErrors();
    }
}
