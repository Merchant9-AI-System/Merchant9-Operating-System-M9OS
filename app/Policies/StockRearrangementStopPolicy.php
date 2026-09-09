<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockRearrangementStop;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockRearrangementStopPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockRearrangementStop');
    }

    public function view(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('View:StockRearrangementStop');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockRearrangementStop');
    }

    public function update(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('Update:StockRearrangementStop');
    }

    public function delete(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('Delete:StockRearrangementStop');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StockRearrangementStop');
    }

    public function restore(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('Restore:StockRearrangementStop');
    }

    public function forceDelete(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('ForceDelete:StockRearrangementStop');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StockRearrangementStop');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockRearrangementStop');
    }

    public function replicate(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('Replicate:StockRearrangementStop');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockRearrangementStop');
    }

    public function approve(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('Approve:StockRearrangementStop');
    }

    public function reject(AuthUser $authUser, StockRearrangementStop $stockRearrangementStop): bool
    {
        return $authUser->can('Reject:StockRearrangementStop');
    }
}
