<?php

namespace Tests\Feature;

use App\Filament\Widgets\BranchHealthTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BranchHealthTableTest extends TestCase
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
            ->assertSee('Branch Health');
    }

    public function test_widget_is_livewire_testable(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(BranchHealthTable::class)->assertSuccessful();
    }

    public function test_feature_flag_disables_widget(): void
    {
        config(['dashboard.ceo_features.branch_health' => false]);

        $this->assertFalse(BranchHealthTable::canView());
    }
}
