<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HqDirection;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Kebenaran arahan HQ ikut Shield (format Tindakan:Model). Hanya super_admin diberi kebenaran secara lalai
 * (ShieldSeeder) - super_admin menetapkan peranan lain (cth. ceo) melalui halaman Roles. Kebenaran widget
 * (View:HqDirectionsWidget) menentukan siapa NAMPAK widget; kebenaran di bawah menentukan siapa boleh cipta/ubah.
 */
class HqDirectionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:HqDirection');
    }

    public function view(AuthUser $authUser, HqDirection $hqDirection): bool
    {
        return $authUser->can('View:HqDirection');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:HqDirection');
    }

    public function update(AuthUser $authUser, HqDirection $hqDirection): bool
    {
        return $authUser->can('Update:HqDirection');
    }

    public function delete(AuthUser $authUser, HqDirection $hqDirection): bool
    {
        return $authUser->can('Delete:HqDirection');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:HqDirection');
    }

    public function restore(AuthUser $authUser, HqDirection $hqDirection): bool
    {
        return $authUser->can('Restore:HqDirection');
    }

    public function forceDelete(AuthUser $authUser, HqDirection $hqDirection): bool
    {
        return $authUser->can('ForceDelete:HqDirection');
    }
}
