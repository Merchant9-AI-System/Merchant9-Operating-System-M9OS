<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\StockTransfer;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockTransferPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockTransfer');
    }

    public function view(AuthUser $authUser, StockTransfer $stockTransfer): bool
    {
        return $authUser->can('View:StockTransfer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockTransfer');
    }

    public function update(AuthUser $authUser, StockTransfer $stockTransfer): bool
    {
        return $authUser->can('Update:StockTransfer');
    }

    public function delete(AuthUser $authUser, StockTransfer $stockTransfer): bool
    {
        return $authUser->can('Delete:StockTransfer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StockTransfer');
    }

    public function restore(AuthUser $authUser, StockTransfer $stockTransfer): bool
    {
        return $authUser->can('Restore:StockTransfer');
    }

    public function forceDelete(AuthUser $authUser, StockTransfer $stockTransfer): bool
    {
        return $authUser->can('ForceDelete:StockTransfer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StockTransfer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockTransfer');
    }

    public function replicate(AuthUser $authUser, StockTransfer $stockTransfer): bool
    {
        return $authUser->can('Replicate:StockTransfer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockTransfer');
    }

}