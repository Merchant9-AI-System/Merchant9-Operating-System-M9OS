<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission widget dashboard "Aktiviti Cawangan" (App\Filament\Widgets\BranchActivityStats). Idempoten: mencipta permission
 * `View:BranchActivityStats` (sama nama dgn yg dijana `shield:generate --widget=BranchActivityStats --panel=admin`) dan memberinya
 * kpd role yg wujud sahaja - role lain boleh ditambah di halaman Roles (Shield). Jalankan:
 * `php artisan db:seed --class=BranchActivityWidgetPermissionSeeder`.
 */
class BranchActivityWidgetPermissionSeeder extends Seeder
{
    public const PERMISSION = 'View:BranchActivityStats';

    /** @var list<string> */
    public const ROLES = ['leader_branch', 'manager', 'ceo', 'super_admin'];

    public function run(): void
    {
        $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()->whereIn('name', self::ROLES)->get()->each->givePermissionTo($permission);
    }
}
