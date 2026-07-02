<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeManager(): User
    {
        Role::firstOrCreate(['name' => 'manager']);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    protected function makeStaff(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    public function test_guest_is_redirected(): void
    {
        $this->get('/admin/purchase-orders')->assertRedirect('/admin/login');
    }

    public function test_manager_can_view_list_page(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/purchase-orders')
            ->assertOk()
            ->assertSee('Purchase Order');
    }

    public function test_manager_can_view_create_page(): void
    {
        $this->actingAs($this->makeManager())
            ->get('/admin/purchase-orders/create')
            ->assertOk();
    }

    public function test_can_create_po_with_lines_via_form(): void
    {
        $this->actingAs($this->makeManager());

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'vendor_code' => 'ACE',
                'created_by' => 'Haniff',
                'lines' => [
                    ['internal_code' => 'D1', 'item_desc' => 'Cincin A', 'qty_ordered' => 10, 'unit_cost' => 150],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $po = PurchaseOrder::first();
        $this->assertNotNull($po);
        $this->assertSame('ACE', $po->vendor_code);
        $this->assertSame(1, $po->lines()->count());
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->status);
    }

    public function test_view_page_renders_with_lines_and_receiving_action(): void
    {
        $manager = $this->makeManager();
        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'vendor_name' => 'Test', 'created_by' => 'Haniff']);
        $po->lines()->create(['internal_code' => 'D1', 'item_desc' => 'Cincin A', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->submitForApproval();
        $po->approve('Haniff');
        $po->markAsSent();

        $this->actingAs($manager)
            ->get("/admin/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertSee($po->po_number)
            ->assertSee('Terima Barang');
    }

    public function test_receive_goods_action_creates_grn_and_updates_status(): void
    {
        $manager = $this->makeManager();
        $po = PurchaseOrder::create(['vendor_code' => 'ACE', 'vendor_name' => 'Test', 'created_by' => 'Haniff']);
        $line = $po->lines()->create(['internal_code' => 'D1', 'item_desc' => 'Cincin A', 'qty_ordered' => 10, 'unit_cost' => 150]);
        $po->submitForApproval();
        $po->approve('Haniff');
        $po->markAsSent();

        $this->actingAs($manager);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $po->getKey()])
            ->mountAction('receiveGoods', ['record' => $po->getKey()])
            ->setActionData(["qty_{$line->id}" => 6, 'grn_notes' => 'Penghantaran pertama'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $po->status);
        $this->assertSame(6, $po->lines()->first()->qty_received);
        $this->assertSame(1, $po->goodsReceipts()->count());
    }
}
