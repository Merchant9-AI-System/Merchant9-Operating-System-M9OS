<?php

namespace Tests\Feature;

use App\Filament\Pages\StockoutReorder;
use App\Filament\Widgets\BestSellerLostOpportunityStats;
use App\Filament\Widgets\BestSellerLostOpportunityTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BestSellerLostOpportunityTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_stockout_reorder_page_still_loads_with_new_header_widgets(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/stockout-reorder')
            ->assertOk()
            ->assertSee('Best-seller Sold Out')
            ->assertSee('Total Design Sold Out')
            ->assertSee('Top 10 Sold-Out Designs');
    }

    public function test_stats_widget_is_livewire_testable(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(BestSellerLostOpportunityStats::class)->assertSuccessful();
    }

    public function test_table_widget_is_livewire_testable(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(BestSellerLostOpportunityTable::class)->assertSuccessful();
    }

    public function test_feature_flag_disables_both_widgets(): void
    {
        config(['dashboard.ceo_features.lost_opportunity' => false]);

        $this->assertFalse(BestSellerLostOpportunityStats::canView());
        $this->assertFalse(BestSellerLostOpportunityTable::canView());
    }

    public function test_existing_stockout_reorder_table_still_works(): void
    {
        // Regresi: pastikan table/filter/export sedia ada di StockoutReorder tak terjejas
        // oleh header widget baru.
        $this->actingAs($this->makeManager());

        Livewire::test(StockoutReorder::class)
            ->assertSuccessful()
            ->filterTable('CategoryCode', 'XX')
            ->assertSuccessful();
    }
}
