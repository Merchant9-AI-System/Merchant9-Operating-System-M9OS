<?php

namespace Tests\Feature;

use App\Filament\Widgets\CapitalAgingSummary;
use App\Models\User;
use App\Support\CapitalAgingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CapitalAgingSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_widget_renders_on_dashboard(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Dead Stock');
    }

    public function test_widget_is_livewire_testable(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(CapitalAgingSummary::class)->assertSuccessful();
    }

    public function test_feature_flag_disables_widget(): void
    {
        config(['dashboard.ceo_features.capital_trend' => false]);

        $this->assertFalse(CapitalAgingSummary::canView());
    }

    public function test_no_historical_trend_is_invented(): void
    {
        $this->assertFalse(CapitalAgingCalculator::HAS_HISTORICAL_DATA);
    }
}
