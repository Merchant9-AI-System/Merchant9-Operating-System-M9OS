<?php

namespace App\Models;

use Database\Factories\HomeRoleSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Suis on/off halaman /home bagi satu peranan (rujuk App\Support\HomeSummary\HomeAccess).
 */
#[Fillable(['role_name', 'enabled'])]
class HomeRoleSetting extends Model
{
    /** @use HasFactory<HomeRoleSettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
