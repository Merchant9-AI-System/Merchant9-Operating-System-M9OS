<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\BudgetPeriod;
use Illuminate\Auth\Access\HandlesAuthorization;

class BudgetPeriodPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BudgetPeriod');
    }

    public function view(AuthUser $authUser, BudgetPeriod $budgetPeriod): bool
    {
        return $authUser->can('View:BudgetPeriod');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BudgetPeriod');
    }

    public function update(AuthUser $authUser, BudgetPeriod $budgetPeriod): bool
    {
        return $authUser->can('Update:BudgetPeriod');
    }

    public function delete(AuthUser $authUser, BudgetPeriod $budgetPeriod): bool
    {
        return $authUser->can('Delete:BudgetPeriod');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:BudgetPeriod');
    }

    public function restore(AuthUser $authUser, BudgetPeriod $budgetPeriod): bool
    {
        return $authUser->can('Restore:BudgetPeriod');
    }

    public function forceDelete(AuthUser $authUser, BudgetPeriod $budgetPeriod): bool
    {
        return $authUser->can('ForceDelete:BudgetPeriod');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:BudgetPeriod');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:BudgetPeriod');
    }

    public function replicate(AuthUser $authUser, BudgetPeriod $budgetPeriod): bool
    {
        return $authUser->can('Replicate:BudgetPeriod');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:BudgetPeriod');
    }

}