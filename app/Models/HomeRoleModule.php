<?php

namespace App\Models;

use Database\Factories\HomeRoleModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu modul pada susun atur /home bagi satu peranan (rujuk App\Support\HomeSummary\HomeLayout).
 *
 * @property array<int, string>|null $scope_stores
 */
#[Fillable(['role_name', 'module_key', 'enabled', 'sort_order', 'scope_mode', 'scope_stores'])]
class HomeRoleModule extends Model
{
    /** @use HasFactory<HomeRoleModuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort_order' => 'integer', 'scope_stores' => 'array'];
    }
}
