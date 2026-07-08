<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\DailyAssetPosition;
use Illuminate\Auth\Access\HandlesAuthorization;

class DailyAssetPositionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DailyAssetPosition');
    }

    public function view(AuthUser $authUser, DailyAssetPosition $dailyAssetPosition): bool
    {
        return $authUser->can('View:DailyAssetPosition');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DailyAssetPosition');
    }

    public function update(AuthUser $authUser, DailyAssetPosition $dailyAssetPosition): bool
    {
        return $authUser->can('Update:DailyAssetPosition');
    }

    public function delete(AuthUser $authUser, DailyAssetPosition $dailyAssetPosition): bool
    {
        return $authUser->can('Delete:DailyAssetPosition');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DailyAssetPosition');
    }

    public function restore(AuthUser $authUser, DailyAssetPosition $dailyAssetPosition): bool
    {
        return $authUser->can('Restore:DailyAssetPosition');
    }

    public function forceDelete(AuthUser $authUser, DailyAssetPosition $dailyAssetPosition): bool
    {
        return $authUser->can('ForceDelete:DailyAssetPosition');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DailyAssetPosition');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DailyAssetPosition');
    }

    public function replicate(AuthUser $authUser, DailyAssetPosition $dailyAssetPosition): bool
    {
        return $authUser->can('Replicate:DailyAssetPosition');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DailyAssetPosition');
    }

}