<?php

namespace Tests\Feature;

use App\Filament\Pages\Rearrange;
use App\Filament\Pages\StockRearrangementRecommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockRearrangementRecommendationTest extends TestCase
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
            ->get('/admin/stock-rearrangement-recommendation')
            ->assertOk()
            ->assertSee('From Branch')
            ->assertSee('To Branch');
    }

    public function test_page_is_livewire_testable(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(StockRearrangementRecommendation::class)->assertSuccessful();
    }

    public function test_feature_flag_disables_page_access_and_navigation(): void
    {
        config(['dashboard.ceo_features.rearrangement' => false]);

        $this->assertFalse(StockRearrangementRecommendation::canAccess());
        $this->assertFalse(StockRearrangementRecommendation::shouldRegisterNavigation());
    }

    public function test_existing_rearrange_page_untouched(): void
    {
        // Regresi: pastikan page Rearrange sedia ada (dgn tindakan tulis "Cipta Transfer")
        // tak terjejas oleh page read-only baru ni.
        $this->actingAs($this->makeManager());

        Livewire::test(Rearrange::class)->assertSuccessful();
    }
}
