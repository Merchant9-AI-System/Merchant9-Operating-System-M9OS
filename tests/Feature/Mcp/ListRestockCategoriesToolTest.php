<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\RestockServer;
use App\Mcp\Tools\ListRestockCategoriesTool;
use App\Models\Jemisys\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListRestockCategoriesToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_categories_ordered_by_name(): void
    {
        $this->makeCategory('RT', 'RANTAI TANGAN');
        $this->makeCategory('GB', 'GELANG');

        $response = RestockServer::tool(ListRestockCategoriesTool::class);

        $response->assertOk()->assertStructuredContent([
            'categories' => [
                ['code' => 'GB', 'name' => 'GELANG'],
                ['code' => 'RT', 'name' => 'RANTAI TANGAN'],
            ],
        ]);
    }

    public function test_it_excludes_the_blank_category_code(): void
    {
        $this->makeCategory('', 'TIADA KATEGORI');
        $this->makeCategory('RT', 'RANTAI TANGAN');

        $response = RestockServer::tool(ListRestockCategoriesTool::class);

        $response->assertOk()->assertStructuredContent([
            'categories' => [
                ['code' => 'RT', 'name' => 'RANTAI TANGAN'],
            ],
        ]);
    }

    protected function makeCategory(string $code, string $description): Category
    {
        return Category::forceCreate([
            'CategoryCode' => $code,
            'CategoryGroup' => 'DEFAULT',
            'Description' => $description,
            'AutoNoFrom' => '1',
            'AutoNoTo' => '9999',
            'NextAutoNo' => 1,
            'NextInternalCode' => 1,
            'IsMiscCategory' => false,
            'ExchangeDays' => 0,
            'OrderOfDisplay' => 0,
            'CreatedDate' => now(),
            'ModifiedDate' => now(),
            'UpgradeDays' => 0,
            'IsGSTExempted' => false,
            'IsNoStockCategory' => false,
            'IsFreeGiftCategory' => false,
            'TransferThruTR' => false,
            'ItemRemarksPOSVisible' => false,
            'CanPrint' => true,
            'PromotionDays' => 0,
            'CostPrice' => 0,
            'ListingPrice' => 0,
            'synced_at' => now(),
        ]);
    }
}
