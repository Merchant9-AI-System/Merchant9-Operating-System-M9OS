<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyAssetPositions\DailyAssetPositionResource;
use App\Filament\Resources\DailyAssetPositions\Pages\CreateDailyAssetPosition;
use App\Filament\Resources\DailyAssetPositions\Pages\EditDailyAssetPosition;
use App\Filament\Resources\DailyAssetPositions\Pages\ListDailyAssetPositions;
use App\Models\DailyAssetPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailyAssetPositionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function validFormData(array $overrides = []): array
    {
        return array_merge([
            'entry_date' => '2026-07-01',
            'opening_stock_weight' => 1000,
            'new_stock' => 100,
            'used_gold' => 0,
            'gold_bar' => 0,
            'unreceived_bar' => 0,
            'loan_received' => 0,
            'sales' => 50,
            'payment_to_supplier' => 0,
            'stock_out_return' => 0,
            'loss_from_melting' => 0,
            'loan_out' => 0,
            'closing_stock' => 1050,
            'supplier_hutang' => 0,
            'supplier_overpaid' => 0,
            'ambank_balance' => 0,
            'affin_balance' => 0,
            'cash' => 0,
            'affin_rm' => 0,
            'od_affin' => 0,
            'locked_gold_bar' => 0,
            'notes' => null,
        ], $overrides);
    }

    public function test_accountant_can_view_list_page(): void
    {
        $this->actingAs($this->makeUser('accountant'))
            ->get('/admin/daily-asset-positions')
            ->assertOk();
    }

    public function test_ceo_role_can_view_but_not_create(): void
    {
        $this->actingAs($this->makeUser('manager'));

        Livewire::test(ListDailyAssetPositions::class)->assertSuccessful();

        $this->assertFalse(DailyAssetPositionResource::canCreate());
    }

    public function test_accountant_can_create_entry(): void
    {
        $this->actingAs($this->makeUser('accountant'));

        Livewire::test(CreateDailyAssetPosition::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(DailyAssetPosition::whereDate('entry_date', '2026-07-01')->exists());
    }

    public function test_non_accountant_cannot_create_entry(): void
    {
        $this->actingAs($this->makeUser('staff'));

        $this->assertFalse(DailyAssetPositionResource::canCreate());
    }

    public function test_notes_required_when_closing_stock_mismatch(): void
    {
        $this->actingAs($this->makeUser('accountant'));

        Livewire::test(CreateDailyAssetPosition::class)
            ->fillForm($this->validFormData(['closing_stock' => 9999, 'notes' => null]))
            ->call('create')
            ->assertHasFormErrors(['notes' => 'required']);
    }

    public function test_saves_successfully_with_mismatch_when_notes_provided(): void
    {
        $this->actingAs($this->makeUser('accountant'));

        Livewire::test(CreateDailyAssetPosition::class)
            ->fillForm($this->validFormData(['closing_stock' => 9999, 'notes' => 'Timbang semula fizikal, beza sebab X.']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_admin_can_delete_entry(): void
    {
        $entry = DailyAssetPosition::create($this->validFormData(['created_by' => 'Seed']));

        $this->assertTrue(DailyAssetPositionResource::canDelete($entry) === false);

        $this->actingAs($this->makeUser('admin'));
        $this->assertTrue(DailyAssetPositionResource::canDelete($entry));
    }

    public function test_accountant_cannot_delete_entry(): void
    {
        $entry = DailyAssetPosition::create($this->validFormData(['created_by' => 'Seed']));
        $this->actingAs($this->makeUser('accountant'));

        $this->assertFalse(DailyAssetPositionResource::canDelete($entry));
    }

    public function test_editing_sets_updated_by(): void
    {
        $entry = DailyAssetPosition::create($this->validFormData(['created_by' => 'Seed']));
        $accountant = $this->makeUser('accountant');
        $this->actingAs($accountant);

        // Ubah sales & closing_stock bersama supaya formula tetap sepadan (elak amaran mismatch
        // yg wajibkan Notes - itu diuji berasingan di DailyAssetPositionTest/create-mismatch test).
        Livewire::test(EditDailyAssetPosition::class, ['record' => $entry->getKey()])
            ->fillForm(['sales' => 75, 'closing_stock' => 1025])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($accountant->name, $entry->fresh()->updated_by);
    }
}
