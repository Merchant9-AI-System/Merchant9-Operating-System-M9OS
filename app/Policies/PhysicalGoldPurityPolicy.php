<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PhysicalGoldPurity;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PhysicalGoldPurityPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PhysicalGoldPurity');
    }

    public function view(AuthUser $authUser, PhysicalGoldPurity $physicalGoldPurity): bool
    {
        return $authUser->can('View:PhysicalGoldPurity');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PhysicalGoldPurity');
    }

    public function update(AuthUser $authUser, PhysicalGoldPurity $physicalGoldPurity): bool
    {
        return $authUser->can('Update:PhysicalGoldPurity');
    }

    public function delete(AuthUser $authUser, PhysicalGoldPurity $physicalGoldPurity): bool
    {
        return $authUser->can('Delete:PhysicalGoldPurity');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PhysicalGoldPurity');
    }

    public function restore(AuthUser $authUser, PhysicalGoldPurity $physicalGoldPurity): bool
    {
        return $authUser->can('Restore:PhysicalGoldPurity');
    }

    public function forceDelete(AuthUser $authUser, PhysicalGoldPurity $physicalGoldPurity): bool
    {
        return $authUser->can('ForceDelete:PhysicalGoldPurity');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PhysicalGoldPurity');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PhysicalGoldPurity');
    }

    public function replicate(AuthUser $authUser, PhysicalGoldPurity $physicalGoldPurity): bool
    {
        return $authUser->can('Replicate:PhysicalGoldPurity');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PhysicalGoldPurity');
    }
}
