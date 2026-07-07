<?php

namespace Tests\Feature;

use App\Filament\Pages\StockoutReorder;
use App\Models\Jemisys\InventoryPiece;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockoutReorderPageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_page_loads(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/stockout-reorder')
            ->assertOk()
            ->assertSee('Best-seller Sold Out');
    }

    public function test_shows_expected_count(): void
    {
        $this->actingAs($this->makeManager());

        // Kira dinamik (bukan hardcode) - sepadan definisi query dlm StockoutReorder::baseQuery().
        $expected = InventoryPiece::query()->realVendor()
            ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode')
            ->havingRaw('sold >= 3 AND stock = 0')
            ->get()
            ->count();

        Livewire::test(StockoutReorder::class)
            ->assertSuccessful()
            ->assertCountTableRecords($expected);
    }

    public function test_category_filter_narrows_results(): void
    {
        $this->actingAs($this->makeManager());

        $categoryCode = InventoryPiece::query()->realVendor()
            ->selectRaw('CategoryCode, InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode', 'CategoryCode')
            ->havingRaw('sold >= 3 AND stock = 0')
            ->value('CategoryCode');

        if ($categoryCode === null) {
            $this->markTestSkipped('Tiada data reorder di jemisys utk uji filter kategori.');
        }

        $expected = InventoryPiece::query()->realVendor()
            ->where('CategoryCode', $categoryCode)
            ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode')
            ->havingRaw('sold >= 3 AND stock = 0')
            ->get()
            ->count();

        Livewire::test(StockoutReorder::class)
            ->assertSuccessful()
            ->filterTable('CategoryCode', $categoryCode)
            ->assertCountTableRecords($expected);
    }
}
