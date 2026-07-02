<?php

namespace Tests\Feature;

use App\Filament\Pages\Rearrange;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockTransferResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_manager_can_view_list_page(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/stock-transfers')
            ->assertOk()
            ->assertSee('Stock Transfer');
    }

    public function test_can_create_transfer_via_form(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/stock-transfers/create')
            ->assertOk();
    }

    public function test_advance_action_progresses_status(): void
    {
        $manager = $this->makeManager();
        $t = StockTransfer::create([
            'internal_code' => 'D1', 'item_desc' => 'Cincin A',
            'from_store' => 'HQ', 'to_store' => 'WM', 'qty' => 1, 'requested_by' => 'Haniff',
        ]);

        $this->actingAs($manager)
            ->get("/admin/stock-transfers/{$t->id}")
            ->assertOk()
            ->assertSee($t->transfer_number);
    }

    public function test_rearrange_page_loads_and_shows_recommendations(): void
    {
        Cache::forget('rearrange_recommendations');

        $this->actingAs($this->makeManager())
            ->get('/admin/rearrange')
            ->assertOk()
            ->assertSee('Cadangan Pindahan');
    }

    public function test_create_transfer_action_from_rearrange_page(): void
    {
        Cache::forget('rearrange_recommendations');
        $this->actingAs($this->makeManager());

        $recs = \App\Support\RearrangeCalculator::recommendations();
        $this->assertGreaterThan(0, $recs->count(), 'Perlu sekurang-kurangnya 1 cadangan utk uji create-transfer action.');
        $first = $recs->first();

        $fromStore = trim(explode(' ', $first['donors'])[0]);
        $toStore = trim(explode(' ', $first['receivers'])[0]);

        Livewire::test(Rearrange::class)
            ->mountTableAction('createTransfer', $first['internal_code'])
            ->setTableActionData(['from_store' => $fromStore, 'to_store' => $toStore, 'qty' => 1])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, StockTransfer::where('internal_code', $first['internal_code'])->count());
    }
}
