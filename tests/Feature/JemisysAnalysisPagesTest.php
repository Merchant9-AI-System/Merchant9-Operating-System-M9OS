<?php

namespace Tests\Feature;

use App\Filament\Pages\BranchFocus;
use App\Filament\Pages\RestockBySize;
use App\Filament\Pages\RestockByWeight;
use App\Filament\Pages\SupplierPerformance;
use App\Filament\Widgets\StockVsOptimumChart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JemisysAnalysisPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['restock_by_size', 'restock_by_weight', 'supplier_performance_jemisys', 'branch_focus', 'stock_vs_optimum_by_category'] as $key) {
            Cache::forget($key);
        }
    }

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_restock_by_size_page_loads(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/restock-by-size')
            ->assertOk()
            ->assertSee('Perlu Restock');
    }

    public function test_restock_by_weight_page_loads(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/restock-by-weight')
            ->assertOk();
    }

    public function test_supplier_performance_page_loads(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/supplier-performance')
            ->assertOk();
    }

    public function test_branch_focus_page_loads(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/branch-focus')
            ->assertOk();
    }

    public function test_restock_by_size_filters_work(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(RestockBySize::class)
            ->assertSuccessful()
            ->filterTable('store_code', 'HQ')
            ->assertSuccessful();
    }

    public function test_branch_focus_filters_work(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(BranchFocus::class)
            ->assertSuccessful()
            ->filterTable('focus_area', 'Understock - Fokus Beli')
            ->assertSuccessful();
    }

    public function test_supplier_performance_table_renders(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(SupplierPerformance::class)->assertSuccessful();
    }

    public function test_stock_vs_optimum_widget_renders(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(StockVsOptimumChart::class)->assertSuccessful();
    }

    public function test_dashboard_still_loads_with_new_widget(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Stok Semasa vs Stok Optimum');
    }
}
