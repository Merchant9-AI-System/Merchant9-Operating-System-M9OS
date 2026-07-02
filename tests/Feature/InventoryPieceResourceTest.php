<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryPieces\Pages\ListInventoryPieces;
use App\Models\Jemisys\InventoryPiece;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryPieceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_guest_is_redirected_from_admin_panel(): void
    {
        $this->get('/admin/inventory-pieces')->assertRedirect('/admin/login');
    }

    public function test_manager_can_view_inventory_list_page(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/inventory-pieces')
            ->assertOk()
            ->assertSee('Stok Semasa');
    }

    public function test_admin_login_page_loads(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_manager_can_view_single_record_page(): void
    {
        $piece = InventoryPiece::onHand()->realVendor()->first();

        $this->actingAs($this->makeManager())
            ->get("/admin/inventory-pieces/{$piece->getKey()}")
            ->assertOk();
    }

    public function test_table_filters_render_without_error(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(ListInventoryPieces::class)
            ->assertCountTableRecords(InventoryPiece::onHand()->realVendor()->count())
            ->assertSuccessful();
    }

    public function test_store_filter_narrows_results(): void
    {
        $this->actingAs($this->makeManager());

        $expectedCount = InventoryPiece::onHand()->realVendor()->where('StoreCode', 'HQ')->count();

        Livewire::test(ListInventoryPieces::class)
            ->filterTable('StoreCode', 'HQ')
            ->assertCountTableRecords($expectedCount)
            ->assertSuccessful();
    }

    public function test_category_filter_does_not_throw_null_label_error(): void
    {
        $this->actingAs($this->makeManager());

        // Ini regresi ujian untuk bug "Argument #2 ($label) must be ... null given" -
        // berlaku bila Category.Description null untuk baris CategoryCode kosong.
        Livewire::test(ListInventoryPieces::class)
            ->assertSuccessful();
    }

    public function test_export_action_is_mountable(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(ListInventoryPieces::class)
            ->mountAction('export')
            ->assertSuccessful();
    }
}
