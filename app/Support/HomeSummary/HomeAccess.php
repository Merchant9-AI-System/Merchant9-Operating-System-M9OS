<?php

namespace App\Support\HomeSummary;

use App\Models\HomeRoleSetting;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Siapa yang dapat halaman /home (ringkasan semalam). Pengurus (halaman Filament "Halaman Home") menetapkan
 * on/off bagi setiap PERANAN - termasuk super_admin; pengguna layak jika MANA-MANA peranannya dihidupkan.
 * Peranan tanpa tetapan guna nilai lalai (DEFAULT_ENABLED). Mematikan /home tidak menghalang sesiapa daripada
 * dashboard admin atau halaman tetapan ini, jadi tiada risiko "mengunci diri sendiri".
 *
 * Bila tak layak: tiada pengalihan ke /home selepas log masuk dan /home menghantar balik ke dashboard admin.
 */
class HomeAccess
{
    /** Peranan yang dihidupkan secara lalai selagi super_admin belum menetapkan apa-apa. */
    public const DEFAULT_ENABLED = ['leader_branch', 'ceo'];

    /**
     * Status on/off setiap peranan (nama peranan => bool), semua peranan termasuk super_admin.
     *
     * @return array<string, bool>
     */
    public static function roleStates(): array
    {
        $saved = HomeRoleSetting::query()->pluck('enabled', 'role_name');

        return Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->mapWithKeys(fn (string $role) => [
                $role => $saved->has($role) ? (bool) $saved[$role] : in_array($role, self::DEFAULT_ENABLED, true),
            ])
            ->all();
    }

    public static function enabledFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $states = self::roleStates();

        return $user->getRoleNames()->contains(fn (string $role) => $states[$role] ?? false);
    }

    /**
     * @param  array<string, bool>  $states  nama peranan => on/off
     */
    public static function save(array $states): void
    {
        foreach ($states as $role => $enabled) {
            HomeRoleSetting::query()->updateOrCreate(['role_name' => $role], ['enabled' => (bool) $enabled]);
        }
    }
}
