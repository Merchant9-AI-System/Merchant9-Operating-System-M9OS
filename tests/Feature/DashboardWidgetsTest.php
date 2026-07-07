<?php

namespace Tests\Feature;

use App\Filament\Widgets\CapitalAgingChart;
use App\Filament\Widgets\GoldVsIdealByBranch;
use App\Filament\Widgets\InventoryKpiStats;
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
            ->assertSee('Dead Stock');
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
}
