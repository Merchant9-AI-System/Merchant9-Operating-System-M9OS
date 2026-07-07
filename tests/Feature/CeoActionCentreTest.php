<?php

namespace Tests\Feature;

use App\Filament\Widgets\CeoActionCentre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CeoActionCentreTest extends TestCase
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
            ->assertSee('CEO Action Centre');
    }

    public function test_widget_is_livewire_testable(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(CeoActionCentre::class)->assertSuccessful();
    }

    public function test_feature_flag_disables_widget(): void
    {
        config(['dashboard.ceo_features.action_centre' => false]);

        $this->assertFalse(CeoActionCentre::canView());
    }
}
