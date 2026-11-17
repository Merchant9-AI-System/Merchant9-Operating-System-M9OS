<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChecklistItem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Kebenaran Resource "Daily Checklist" (templat) ikut Shield. super_admin menetapkan peranan yang boleh mengurus
 * templat melalui halaman Roles. Ketua cawangan hanya menanda item di /home (ChecklistToggleController).
 */
class ChecklistItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ChecklistItem');
    }

    public function view(AuthUser $authUser, ChecklistItem $checklistItem): bool
    {
        return $authUser->can('View:ChecklistItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ChecklistItem');
    }

    public function update(AuthUser $authUser, ChecklistItem $checklistItem): bool
    {
        return $authUser->can('Update:ChecklistItem');
    }

    public function delete(AuthUser $authUser, ChecklistItem $checklistItem): bool
    {
        return $authUser->can('Delete:ChecklistItem');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ChecklistItem');
    }
}
