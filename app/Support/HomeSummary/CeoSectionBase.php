<?php

namespace App\Support\HomeSummary;

use App\Models\User;

/**
 * Asas seksyen halaman /home untuk CEO (dan super_admin): peranan yang layak, kad HERO ikut config
 * (dashboard.home.ceo_hero_key) dan pembantu "satu angka gagal -> hanya kad itu papar tidak tersedia".
 */
abstract class CeoSectionBase implements SummarySection
{
    public const ROLES = ['ceo', 'super_admin'];

    protected const UNAVAILABLE = 'Data tidak tersedia';

    public function __construct(protected YesterdaySnapshot $snapshot) {}

    public function appliesTo(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Tandakan kad hero: kad yang `key`-nya sama dgn config dashboard.home.ceo_hero_key.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected static function markHero(array $items): array
    {
        $hero = (string) config('dashboard.home.ceo_hero_key', 'sales');

        return array_map(fn (array $item) => $item + ['hero' => ($item['key'] ?? null) === $hero], $items);
    }

    /** @return array<string, mixed> */
    protected static function unavailable(string $key, string $label, ?string $hint = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => self::UNAVAILABLE, 'hint' => $hint, 'tone' => 'warn'];
    }

    /**
     * Jalankan pembina satu kad; sebarang ralat (JEMiSys/kedaiemas gagal) -> kad "tidak tersedia", kad lain kekal.
     *
     * @param  \Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    protected static function safely(string $key, string $label, \Closure $build): array
    {
        try {
            return $build();
        } catch (\Throwable $e) {
            report($e);

            return self::unavailable($key, $label);
        }
    }
}
