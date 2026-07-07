<?php

namespace Tests\Feature;

use App\Filament\Widgets\DailyAssetPositionCashChart;
use App\Filament\Widgets\DailyAssetPositionReconciliation;
use App\Filament\Widgets\DailyAssetPositionStockChart;
use App\Filament\Widgets\DailyAssetPositionSummary;
use App\Filament\Widgets\DailyAssetPositionSupplierChart;
use App\Models\DailyAssetPosition;
use App\Models\User;
use App\Support\DailyAssetPositionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailyAssetPositionDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_summary_returns_null_with_no_data(): void
    {
        $this->assertNull(DailyAssetPositionCalculator::summary());
    }

    public function test_trend_returns_empty_collection_with_no_data(): void
    {
        $this->assertTrue(DailyAssetPositionCalculator::trend()->isEmpty());
    }

    public function test_summary_widget_shows_empty_state_without_breaking_page(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(DailyAssetPositionSummary::class)
            ->assertSuccessful()
            ->assertSee('Tiada data lagi');
    }

    public function test_summary_widget_computes_from_latest_entry(): void
    {
        DailyAssetPosition::create([
            'entry_date' => '2026-07-01',
            'opening_stock_weight' => 1000,
            'new_stock' => 100,
            'closing_stock' => 1100,
            'ambank_balance' => 1000,
            'affin_balance' => 500,
            'cash' => 200,
            'affin_rm' => 100,
            'od_affin' => 50,
            'created_by' => 'Accountant Test',
        ]);

        $this->actingAs($this->makeManager());

        Livewire::test(DailyAssetPositionSummary::class)
            ->assertSuccessful()
            ->assertSee('1,100.000 g') // closing stock weight card
            ->assertSee('RM 1,750.00'); // available cash = 1000+500+200+100-50
    }

    public function test_stock_chart_widget_renders_without_data(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(DailyAssetPositionStockChart::class)->assertSuccessful();
    }

    public function test_cash_chart_widget_renders_without_data(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(DailyAssetPositionCashChart::class)->assertSuccessful();
    }

    public function test_supplier_chart_widget_renders_without_data(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(DailyAssetPositionSupplierChart::class)->assertSuccessful();
    }

    public function test_reconciliation_widget_shows_empty_state_without_data(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(DailyAssetPositionReconciliation::class)
            ->assertSuccessful()
            ->assertSee('belum tersedia');
    }

    public function test_feature_flag_disables_all_daily_asset_position_widgets(): void
    {
        config(['dashboard.ceo_features.daily_asset_position' => false]);

        $this->assertFalse(DailyAssetPositionSummary::canView());
        $this->assertFalse(DailyAssetPositionStockChart::canView());
        $this->assertFalse(DailyAssetPositionCashChart::canView());
        $this->assertFalse(DailyAssetPositionSupplierChart::canView());
        $this->assertFalse(DailyAssetPositionReconciliation::canView());
    }

    public function test_dashboard_page_still_loads_with_no_daily_asset_position_data(): void
    {
        // Regresi: dashboard sedia ada tak boleh pecah walaupun modul baru ni tiada data.
        $this->actingAs($this->makeManager())
            ->get('/admin')
            ->assertOk();
    }
}
