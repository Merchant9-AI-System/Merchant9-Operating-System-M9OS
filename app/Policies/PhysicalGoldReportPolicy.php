<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PhysicalGoldReport;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PhysicalGoldReportPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PhysicalGoldReport');
    }

    public function view(AuthUser $authUser, PhysicalGoldReport $physicalGoldReport): bool
    {
        return $authUser->can('View:PhysicalGoldReport');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PhysicalGoldReport');
    }

    public function update(AuthUser $authUser, PhysicalGoldReport $physicalGoldReport): bool
    {
        return $authUser->can('Update:PhysicalGoldReport');
    }

    public function delete(AuthUser $authUser, PhysicalGoldReport $physicalGoldReport): bool
    {
        return $authUser->can('Delete:PhysicalGoldReport');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PhysicalGoldReport');
    }

    public function restore(AuthUser $authUser, PhysicalGoldReport $physicalGoldReport): bool
    {
        return $authUser->can('Restore:PhysicalGoldReport');
    }

    public function forceDelete(AuthUser $authUser, PhysicalGoldReport $physicalGoldReport): bool
    {
        return $authUser->can('ForceDelete:PhysicalGoldReport');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PhysicalGoldReport');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PhysicalGoldReport');
    }

    public function replicate(AuthUser $authUser, PhysicalGoldReport $physicalGoldReport): bool
    {
        return $authUser->can('Replicate:PhysicalGoldReport');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PhysicalGoldReport');
    }
}
