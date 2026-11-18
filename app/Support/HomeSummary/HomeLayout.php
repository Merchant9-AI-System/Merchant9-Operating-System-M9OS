<?php

namespace App\Support\HomeSummary;

use App\Models\HomeRoleModule;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Susun atur halaman /home: modul mana (dan urutannya) bagi setiap PERANAN, ditetapkan pentadbir di halaman Filament
 * "Halaman Home" (jadual home_role_modules). Peranan yang belum pernah disimpan guna susun atur LALAI (sama seperti sebelum
 * ciri ini wujud: ketua cawangan = semua modul cawangan; ceo/super_admin = seksyen CEO). Pengguna dengan beberapa
 * peranan mendapat GABUNGAN modul peranan yang dihidupkan (HomeAccess); urutan ikut kedudukan terawal.
 *
 * Modul skop `branch` hanya aktif jika pengguna ada store_code (selainnya dilangkau senyap).
 */
class HomeLayout
{
    /** Susun atur lalai satu peranan: kunci modul mengikut urutan. @return array<int, string> */
    public static function defaultKeys(string $role): array
    {
        return match (true) {
            $role === BranchSection::ROLE => ['hq_directions', 'branch', 'checklist', 'target', 'ranking', 'rearrange', 'stock'],
            in_array($role, CeoSectionBase::ROLES, true) => ['ceo_sales', 'ceo_finance', 'ceo_alerts'],
            default => [],
        };
    }

    /**
     * Susun atur penuh satu peranan utk editor: SEMUA modul yang dibina, mengikut urutan (modul dihidupkan dahulu mengikut
     * urutan simpanan, kemudian yang dimatikan). Peranan tanpa simpanan -> lalai.
     *
     * @return array<int, array{key: string, enabled: bool, scope_mode: string, scope_stores: array<int, string>}>
     */
    public static function forRole(string $role): array
    {
        $saved = HomeRoleModule::query()->where('role_name', $role)->orderBy('sort_order')->get()->keyBy('module_key');
        $keys = HomeModules::keys();
        $rows = [];

        if ($saved->isEmpty()) {
            $default = self::defaultKeys($role);

            foreach ($default as $key) {
                $rows[] = self::row($key, true);
            }

            foreach (array_diff($keys, $default) as $key) {
                $rows[] = self::row($key, false);
            }

            return $rows;
        }

        foreach ($saved as $key => $module) {
            if (in_array($key, $keys, true)) {
                $rows[] = self::row($key, $module->enabled, $module->scope_mode, (array) $module->scope_stores);
            }
        }

        foreach (array_diff($keys, array_column($rows, 'key')) as $key) {
            $rows[] = self::row($key, false);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $stores
     * @return array{key: string, enabled: bool, scope_mode: string, scope_stores: array<int, string>}
     */
    private static function row(string $key, bool $enabled, string $mode = HomeModules::MODE_OWN, array $stores = []): array
    {
        return ['key' => $key, 'enabled' => $enabled, 'scope_mode' => $mode, 'scope_stores' => array_values($stores)];
    }

    /**
     * Simpan susun atur satu peranan (ganti semua baris peranan itu). Urutan tatasusunan = sort_order.
     *
     * @param  array<int, array{key: string, enabled?: bool, scope_mode?: string, scope_stores?: array<int, string>}>  $rows
     */
    public static function saveRole(string $role, array $rows): void
    {
        $valid = HomeModules::keys();

        HomeRoleModule::query()->where('role_name', $role)->delete();

        foreach (array_values($rows) as $index => $row) {
            if (! in_array($row['key'] ?? null, $valid, true)) {
                continue;
            }

            $mode = in_array($row['scope_mode'] ?? null, [HomeModules::MODE_OWN, HomeModules::MODE_ALL, HomeModules::MODE_SELECTED], true)
                ? $row['scope_mode']
                : HomeModules::MODE_OWN;

            HomeRoleModule::query()->create([
                'role_name' => $role,
                'module_key' => $row['key'],
                'enabled' => (bool) ($row['enabled'] ?? false),
                'sort_order' => $index,
                'scope_mode' => $mode,
                'scope_stores' => $mode === HomeModules::MODE_SELECTED ? array_values((array) ($row['scope_stores'] ?? [])) : null,
            ]);
        }
    }

    /** Padam simpanan peranan -> kembali ke susun atur lalai. */
    public static function resetRole(string $role): void
    {
        HomeRoleModule::query()->where('role_name', $role)->delete();
    }

    /**
     * Adakah peranan ini "ada cawangan" (pengguna berperanan ini ada store_code)? Menentukan sama ada modul skop
     * `branch` boleh digunakan (editor memalapkannya jika tidak). Peranan ketua cawangan sentiasa ya.
     */
    public static function roleHasBranch(string $role): bool
    {
        if ($role === BranchSection::ROLE) {
            return true;
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->whereNotNull('store_code')
            ->where('store_code', '<>', '')
            ->exists();
    }

    /**
     * Modul yang dipasang utk SATU pengguna, mengikut urutan (gabungan semua peranannya yang dihidupkan).
     * Setiap entri = metadata HomeModules + `stores` (null = semua cawangan; senarai = cawangan dibenarkan).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(User $user): array
    {
        $states = HomeAccess::roleStates();
        $store = BranchSection::storeOf($user);
        $merged = [];

        foreach ($user->getRoleNames() as $role) {
            if (! ($states[$role] ?? false)) {
                continue;
            }

            foreach (self::forRole($role) as $position => $row) {
                $module = HomeModules::find($row['key']);

                if (! $row['enabled'] || $module === null || ! $module['implemented']) {
                    continue;
                }

                if ($module['scope'] === HomeModules::SCOPE_BRANCH && $store === '') {
                    continue;
                }

                $stores = match (true) {
                    $module['scope'] === HomeModules::SCOPE_BRANCH => [$store],
                    $module['scope'] === HomeModules::SCOPE_COMPANY => null,
                    $row['scope_mode'] === HomeModules::MODE_ALL => null,
                    $row['scope_mode'] === HomeModules::MODE_SELECTED => $row['scope_stores'],
                    default => $store === '' ? [] : [$store],
                };

                // Modul universal skop "cawangan sendiri" bagi pengguna tanpa cawangan = tiada apa dipaparkan -> dilangkau.
                if ($stores === []) {
                    continue;
                }

                $existing = $merged[$row['key']] ?? null;

                $merged[$row['key']] = $existing === null
                    ? $module + ['order' => $position, 'stores' => $stores]
                    : array_merge($existing, [
                        'order' => min($existing['order'], $position),
                        'stores' => ($existing['stores'] === null || $stores === null) ? null : array_values(array_unique([...$existing['stores'], ...$stores])),
                    ]);
            }
        }

        uasort($merged, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_values($merged);
    }

    /** Adakah modul `$key` dipasang utk pengguna ini? */
    public static function has(User $user, string $key): bool
    {
        foreach (self::forUser($user) as $module) {
            if ($module['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> Nama semua peranan sistem (utk editor). */
    public static function roleNames(): array
    {
        return Role::query()->orderBy('name')->pluck('name')->all();
    }
}
