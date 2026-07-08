<?php

declare(strict_types=1);

namespace App\Policies\Jemisys;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Jemisys\InventoryPiece;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryPiecePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InventoryPiece');
    }

    public function view(AuthUser $authUser, InventoryPiece $inventoryPiece): bool
    {
        return $authUser->can('View:InventoryPiece');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InventoryPiece');
    }

    public function update(AuthUser $authUser, InventoryPiece $inventoryPiece): bool
    {
        return $authUser->can('Update:InventoryPiece');
    }

    public function delete(AuthUser $authUser, InventoryPiece $inventoryPiece): bool
    {
        return $authUser->can('Delete:InventoryPiece');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InventoryPiece');
    }

    public function restore(AuthUser $authUser, InventoryPiece $inventoryPiece): bool
    {
        return $authUser->can('Restore:InventoryPiece');
    }

    public function forceDelete(AuthUser $authUser, InventoryPiece $inventoryPiece): bool
    {
        return $authUser->can('ForceDelete:InventoryPiece');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InventoryPiece');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InventoryPiece');
    }

    public function replicate(AuthUser $authUser, InventoryPiece $inventoryPiece): bool
    {
        return $authUser->can('Replicate:InventoryPiece');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InventoryPiece');
    }

}