<?php

namespace App\Filament\Pages;

use App\Support\HomeSummary\HomeAccess;
use App\Support\HomeSummary\HomeSummary;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Role;

/**
 * Menghidupkan/mematikan halaman /home (ringkasan semalam) bagi setiap peranan. Pengguna dengan peranan yang
 * dimatikan tidak dialihkan ke /home selepas log masuk dan /home menghantar mereka ke dashboard admin (rujuk
 * App\Support\HomeSummary\HomeAccess). Akses halaman ini ikut permission Shield "View:HomePageSettings"
 * (HasPageShield) - super_admin sentiasa boleh; peranan lain hanya jika diberi permission itu.
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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->enabled = HomeAccess::roleStates();
    }

    /**
     * Baris paparan: setiap peranan dengan bilangan pengguna & sama ada ia sudah ada seksyen ringkasan.
     *
     * @return array<int, array{role: string, label: string, users: int, has_summary: bool}>
     */
    public function roleRows(): array
    {
        $users = Role::query()->withCount('users')->pluck('users_count', 'name');

        return collect(array_keys($this->enabled))
            ->map(fn (string $role) => [
                'role' => $role,
                'label' => str($role)->replace('_', ' ')->title()->toString(),
                'users' => (int) ($users[$role] ?? 0),
                'has_summary' => in_array($role, HomeSummary::ROLES_WITH_SUMMARY, true),
            ])
            ->all();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        HomeAccess::save(array_map(fn ($value) => (bool) $value, $this->enabled));

        Notification::make()
            ->title('Tetapan Halaman Home disimpan.')
            ->success()
            ->send();
    }
}
