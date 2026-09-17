<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restructure: claimant (siapa expense tu untuk, cth. CEO En Haniff) BUKAN semestinya org yg
 * key-in claim (staf Finance cth. Aqilah yg buat kemasukan bagi pihak claimant). Jadi 2 peranan
 * berasingan:
 * - `finance` — staf yg CIPTA/kemaskini claim (permukaan Inertia /claims), TIADA kuasa
 *   Approve/Reject (elak konflik - staf yg key-in tak patut jugak yg luluskan).
 * - `head_finance` (BAHARU) — SATU-SATUNYA yg Lulus/Tolak (gantikan ceo/manager sbg approver -
 *   CEO/manager tak sepatutnya approve claim yg claimant-nya diri sendiri/rakan sejawat).
 * `super_admin` kekal ada kuasa penuh (override/bypass, ikut corak sedia ada app ni).
 * Jalankan SEKALI: php artisan db:seed --class=ExpenseClaimPermissionSeeder
 */
class ExpenseClaimPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['Approve:ExpenseClaim', 'Reject:ExpenseClaim'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // ->toArray() WAJIB - revokePermissionTo() (tak spt givePermissionTo()) senyap TAK
        // BUAT APA2 bila dihantar Illuminate\Support\Collection terus (buggy pakej Spatie versi
        // ni, disahkan cubaan terus), kena plain array.
        $allPermissions = Permission::where('name', 'like', '%:ExpenseClaim')->pluck('name')->toArray();
        $approvalPermissions = Permission::whereIn('name', ['Approve:ExpenseClaim', 'Reject:ExpenseClaim'])->pluck('name')->toArray();
        $viewPermissions = Permission::whereIn('name', ['ViewAny:ExpenseClaim', 'View:ExpenseClaim'])->pluck('name')->toArray();

        // head_finance - approver TUNGGAL (+ super_admin sbg override).
        foreach (['head_finance', 'super_admin'] as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->givePermissionTo($allPermissions);
        }

        // finance - cipta/urus claim sahaja, boleh lihat (Filament) tapi TIADA Approve/Reject.
        $financeRole = Role::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
        $financeRole->givePermissionTo($viewPermissions);
        $financeRole->revokePermissionTo($approvalPermissions);

        // ceo/manager - BUKAN lagi approver claim (rujuk restructure, claimant kini boleh jadi
        // CEO sendiri - konflik kalau CEO sendiri yg approve). Tanggal SEMUA permission
        // ExpenseClaim drpd role ni (kalau ada dari seeding lama).
        foreach (['ceo', 'manager'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $role?->revokePermissionTo($allPermissions);
        }
    }
}
