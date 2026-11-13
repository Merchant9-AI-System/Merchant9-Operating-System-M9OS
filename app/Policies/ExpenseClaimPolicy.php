<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpenseClaim;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExpenseClaimPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExpenseClaim');
    }

    public function view(AuthUser $authUser, ExpenseClaim $expenseClaim): bool
    {
        return $authUser->can('View:ExpenseClaim');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExpenseClaim');
    }

    public function update(AuthUser $authUser, ExpenseClaim $expenseClaim): bool
    {
        return $authUser->can('Update:ExpenseClaim');
    }

    public function delete(AuthUser $authUser, ExpenseClaim $expenseClaim): bool
    {
        return $authUser->can('Delete:ExpenseClaim');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ExpenseClaim');
    }

    public function restore(AuthUser $authUser, ExpenseClaim $expenseClaim): bool
    {
        return $authUser->can('Restore:ExpenseClaim');
    }

    public function forceDelete(AuthUser $authUser, ExpenseClaim $expenseClaim): bool
    {
        return $authUser->can('ForceDelete:ExpenseClaim');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ExpenseClaim');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ExpenseClaim');
    }

    public function replicate(AuthUser $authUser, ExpenseClaim $expenseClaim): bool
    {
        return $authUser->can('Replicate:ExpenseClaim');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ExpenseClaim');
    }
}
