<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PhysicalGoldCategory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PhysicalGoldCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PhysicalGoldCategory');
    }

    public function view(AuthUser $authUser, PhysicalGoldCategory $physicalGoldCategory): bool
    {
        return $authUser->can('View:PhysicalGoldCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PhysicalGoldCategory');
    }

    public function update(AuthUser $authUser, PhysicalGoldCategory $physicalGoldCategory): bool
    {
        return $authUser->can('Update:PhysicalGoldCategory');
    }

    public function delete(AuthUser $authUser, PhysicalGoldCategory $physicalGoldCategory): bool
    {
        return $authUser->can('Delete:PhysicalGoldCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PhysicalGoldCategory');
    }

    public function restore(AuthUser $authUser, PhysicalGoldCategory $physicalGoldCategory): bool
    {
        return $authUser->can('Restore:PhysicalGoldCategory');
    }

    public function forceDelete(AuthUser $authUser, PhysicalGoldCategory $physicalGoldCategory): bool
    {
        return $authUser->can('ForceDelete:PhysicalGoldCategory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PhysicalGoldCategory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PhysicalGoldCategory');
    }

    public function replicate(AuthUser $authUser, PhysicalGoldCategory $physicalGoldCategory): bool
    {
        return $authUser->can('Replicate:PhysicalGoldCategory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PhysicalGoldCategory');
    }
}
