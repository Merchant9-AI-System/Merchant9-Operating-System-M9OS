<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role asas utk modul Branch Demand & Smart Stock Allocation. Permission BranchDemandRequest
 * dijana oleh `php artisan shield:generate --resource=BranchDemandRequestResource` - seeder ni
 * cuma sync subset yg relevan ke role masing2 (branch_staff hantar sahaja, hq_reviewer semak
 * sahaja - guard drpd firstOrCreate() supaya idempoten bila permission blm wujud lagi, cth.
 * fresh install sblm shield:generate dijalankan).
 */
class BranchDemandRolesSeeder extends Seeder
{
    public function run(): void
    {
        $branchStaff = Role::firstOrCreate(['name' => 'branch_staff', 'guard_name' => 'web']);
        $hqReviewer = Role::firstOrCreate(['name' => 'hq_reviewer', 'guard_name' => 'web']);

        $this->syncIfExists($branchStaff, ['ViewAny:BranchDemandRequest', 'View:BranchDemandRequest', 'Create:BranchDemandRequest']);
        $this->syncIfExists($hqReviewer, [
            'ViewAny:BranchDemandRequest', 'View:BranchDemandRequest', 'Update:BranchDemandRequest',
            'View:BranchDemandAllocationSuggestion',
        ]);
    }

    protected function syncIfExists(Role $role, array $permissionNames): void
    {
        $existing = Permission::whereIn('name', $permissionNames)->pluck('name')->all();

        if (! empty($existing)) {
            $role->givePermissionTo($existing);
        }
    }
}
