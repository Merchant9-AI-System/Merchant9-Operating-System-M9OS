<?php

namespace Tests\Feature;

use App\Filament\Widgets\CapitalAgingChart;
use App\Filament\Widgets\GoldVsIdealByBranch;
use App\Filament\Widgets\InventoryKpiStats;
use App\Filament\Widgets\StockoutProvenSellers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_dashboard_page_loads_with_all_widgets(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Nilai Stok')
            ->assertSee('Emas Dipegang')
            ->assertSee('Dead Stock')
            ->assertSee('Best-seller Sold Out');
    }

    public function test_kpi_stats_widget_computes_expected_values(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(InventoryKpiStats::class)->assertSuccessful();
    }

    public function test_capital_aging_chart_renders(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(CapitalAgingChart::class)->assertSuccessful();
    }

    public function test_gold_vs_ideal_chart_renders(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(GoldVsIdealByBranch::class)->assertSuccessful();
    }

    public function test_stockout_widget_shows_expected_count(): void
    {
        $this->actingAs($this->makeManager());

        // Kira dinamik (bukan hardcode) - nombor ni berubah setiap kali data JEMiSys direfresh
        // (load_data.py). Definisi: design pernah laku (>=3) merentas SEMUA saluran (termasuk web)
        // tapi kini stok=0 di semua saluran - sepadan query dlm StockoutProvenSellers widget.
        $expected = \App\Models\Jemisys\InventoryPiece::query()->realVendor()
            ->selectRaw('InternalCode, SUM(CASE WHEN SalesDate IS NOT NULL THEN 1 ELSE 0 END) as sold, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode')
            ->havingRaw('sold >= 3 AND stock = 0')
            ->get()
            ->count();

        // assertCountTableRecords() tak serasi dengan pendekatan ->records() (Collection cache,
        // bukan Eloquent Builder) - assert terus atas rekod widget selepas render.
        $component = Livewire::test(StockoutProvenSellers::class)->assertSuccessful();
        $actual = $component->instance()->getTable()->getRecords()->count();
        $this->assertSame($expected, $actual);
    }
}
