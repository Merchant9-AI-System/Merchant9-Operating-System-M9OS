<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BranchDemandRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BranchDemandRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BranchDemandRequest');
    }

    public function view(AuthUser $authUser, BranchDemandRequest $branchDemandRequest): bool
    {
        return $authUser->can('View:BranchDemandRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BranchDemandRequest');
    }

    public function update(AuthUser $authUser, BranchDemandRequest $branchDemandRequest): bool
    {
        return $authUser->can('Update:BranchDemandRequest');
    }

    public function delete(AuthUser $authUser, BranchDemandRequest $branchDemandRequest): bool
    {
        return $authUser->can('Delete:BranchDemandRequest');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:BranchDemandRequest');
    }

    public function restore(AuthUser $authUser, BranchDemandRequest $branchDemandRequest): bool
    {
        return $authUser->can('Restore:BranchDemandRequest');
    }

    public function forceDelete(AuthUser $authUser, BranchDemandRequest $branchDemandRequest): bool
    {
        return $authUser->can('ForceDelete:BranchDemandRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:BranchDemandRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:BranchDemandRequest');
    }

    public function replicate(AuthUser $authUser, BranchDemandRequest $branchDemandRequest): bool
    {
        return $authUser->can('Replicate:BranchDemandRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:BranchDemandRequest');
    }
}
