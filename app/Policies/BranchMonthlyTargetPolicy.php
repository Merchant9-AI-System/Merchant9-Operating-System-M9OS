<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BranchMonthlyTarget;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Kebenaran Resource "Sasaran Bulanan Cawangan" ikut Shield (Tindakan:Model). super_admin menetapkan peranan HQ yang
 * boleh melihat/mencipta/mengubah sasaran (cth. ceo) melalui halaman Roles. Ketua cawangan menetapkan sasaran
 * cawangan sendiri di /home (BranchTargetController) - tidak melalui policy ini.
 */
class BranchMonthlyTargetPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BranchMonthlyTarget');
    }

    public function view(AuthUser $authUser, BranchMonthlyTarget $branchMonthlyTarget): bool
    {
        return $authUser->can('View:BranchMonthlyTarget');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BranchMonthlyTarget');
    }

    public function update(AuthUser $authUser, BranchMonthlyTarget $branchMonthlyTarget): bool
    {
        return $authUser->can('Update:BranchMonthlyTarget');
    }

    public function delete(AuthUser $authUser, BranchMonthlyTarget $branchMonthlyTarget): bool
    {
        return $authUser->can('Delete:BranchMonthlyTarget');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:BranchMonthlyTarget');
    }
}
