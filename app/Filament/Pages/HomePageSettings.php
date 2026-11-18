<?php

namespace App\Filament\Pages;

use App\Support\HomeSummary\HomeAccess;
use App\Support\HomeSummary\HomeLayout;
use App\Support\HomeSummary\HomeModules;
use App\Support\HomeSummary\StoreScope;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * Tetapan halaman /home bagi setiap peranan: (1) on/off halaman, (2) modul yang dipasang dan urutannya.
 * Pengguna dengan peranan yang dimatikan tidak dialihkan ke /home selepas log masuk dan /home menghantar mereka ke
 * dashboard admin (rujuk HomeAccess). Peranan tanpa tetapan modul menggunakan susun atur lalai (HomeLayout).
 *
 * Kebenaran Shield (dipisahkan): "View:HomePageSettings" (HasPageShield) = boleh melihat halaman ini;
 * "Update:HomePageSettings" = boleh menyimpan perubahan. super_admin sentiasa boleh kedua-duanya.
 *
 * Modul skop `branch` (cawangan sendiri) dimalapkan bagi peranan yang pengguna-penggunanya tiada store_code.
 */
class HomePageSettings extends Page
{
    use HasPageShield;

    protected string $view = 'filament.pages.home-page-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Halaman Home';

    protected static ?string $title = 'Halaman Home';

    protected static string|\UnitEnum|null $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 90;

    /** @var array<string, bool> nama peranan => on/off */
    public array $enabled = [];

    /** @var array<string, array<int, array{key: string, enabled: bool, scope_mode: string, scope_stores: array<int, string>}>> */
    public array $layouts = [];

    /** @var array<string, string> snapshot JSON susun atur semasa dimuat - hanya peranan yang berubah disimpan. */
    public array $original = [];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->enabled = HomeAccess::roleStates();

        foreach (array_keys($this->enabled) as $role) {
            $this->layouts[$role] = HomeLayout::forRole($role);
            $this->original[$role] = json_encode($this->layouts[$role]);
        }
    }

    /** Boleh mengubah tetapan? (permission berasingan drpd "boleh melihat"). */
    public function canUpdate(): bool
    {
        return (bool) Auth::user()?->can('Update:HomePageSettings');
    }

    /**
     * Baris paparan: setiap peranan dengan bilangan pengguna & sama ada ia ada cawangan.
     *
     * @return array<int, array{role: string, label: string, users: int, has_branch: bool, has_summary: bool}>
     */
    public function roleRows(): array
    {
        $users = Role::query()->withCount('users')->pluck('users_count', 'name');

        return collect(array_keys($this->enabled))
            ->map(fn (string $role) => [
                'role' => $role,
                'label' => str($role)->replace('_', ' ')->title()->toString(),
                'users' => (int) ($users[$role] ?? 0),
                'has_branch' => HomeLayout::roleHasBranch($role),
                'has_summary' => array_filter(HomeLayout::forRole($role), fn (array $r) => $r['enabled']) !== [],
            ])
            ->all();
    }

    /** Metadata modul (tajuk, keterangan, skop) utk paparan. @return array<string, array<string, mixed>> */
    public function moduleMeta(): array
    {
        return HomeModules::all();
    }

    /** Kod cawangan yg boleh dipilih utk skop "cawangan dipilih" (modul universal). @return array<int, string> */
    public function storeOptions(): array
    {
        $stores = StoreScope::stores(null);
        sort($stores);

        return $stores;
    }

    /**
     * Susun semula modul satu peranan selepas diseret (SortableJS): `$keys` ialah kunci modul mengikut urutan baharu.
     * Kunci yg tak dikenali diabaikan; modul yg tak disertakan dikekalkan di hujung dalam urutan asal.
     *
     * @param  array<int, string>  $keys
     */
    public function reorder(string $role, array $keys): void
    {
        abort_unless($this->canUpdate(), 403);

        $rows = $this->layouts[$role] ?? null;

        if ($rows === null) {
            return;
        }

        $byKey = array_column($rows, null, 'key');
        $ordered = [];

        foreach ($keys as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }

        $this->layouts[$role] = [...$ordered, ...array_values($byKey)];
    }

    /** Kembalikan susun atur satu peranan kepada lalai (padam simpanan, muat semula). */
    public function resetRole(string $role): void
    {
        abort_unless($this->canUpdate(), 403);
        abort_unless(array_key_exists($role, $this->enabled), 404);

        HomeLayout::resetRole($role);
        $this->layouts[$role] = HomeLayout::forRole($role);
        $this->original[$role] = json_encode($this->layouts[$role]);

        Notification::make()->title('Susun atur '.$role.' dikembalikan kepada lalai.')->success()->send();
    }

    public function save(): void
    {
        abort_unless($this->canUpdate(), 403);

        HomeAccess::save(array_map(fn ($value) => (bool) $value, $this->enabled));

        foreach ($this->layouts as $role => $rows) {
            if (! array_key_exists($role, $this->enabled) || json_encode($rows) === ($this->original[$role] ?? null)) {
                continue;
            }

            // Modul skop cawangan tak boleh dihidupkan utk peranan tanpa cawangan (dimalapkan di paparan) - dipaksa mati di sini juga.
            $hasBranch = HomeLayout::roleHasBranch($role);

            HomeLayout::saveRole($role, array_map(function (array $row) use ($hasBranch) {
                $scope = HomeModules::find($row['key'])['scope'] ?? null;

                if ($scope === HomeModules::SCOPE_BRANCH && ! $hasBranch) {
                    $row['enabled'] = false;
                }

                return $row;
            }, $rows));

            // Muat semula dari simpanan (modul yg dipaksa mati dipaparkan sebagaimana disimpan).
            $this->layouts[$role] = HomeLayout::forRole($role);
            $this->original[$role] = json_encode($this->layouts[$role]);
        }

        Notification::make()
            ->title('Tetapan Halaman Home disimpan.')
            ->success()
            ->send();
    }
}
