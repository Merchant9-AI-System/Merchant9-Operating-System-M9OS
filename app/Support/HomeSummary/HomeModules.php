<?php

namespace App\Support\HomeSummary;

/**
 * Daftar SEMUA seksyen/modul yang boleh dipasang pada halaman /home. Setiap peranan memilih modul (dan urutannya) melalui
 * halaman Filament "Halaman Home" (rujuk HomeLayout). Menambah modul baharu = satu entri di sini + komponen paparan.
 *
 * Jenis (kind):
 *  - section: seksyen ringkasan kad (kelas SummarySection, data dalam prop `sections`).
 *  - panel:   panel khas (prop deferred sendiri + komponen Vue).
 *  - pinned:  panel di atas bar tab, bukan tab (cth. Arahan HQ).
 *
 * Skop data (scope):
 *  - branch:  cawangan pengguna SENDIRI sahaja - perlu users.store_code (malap bagi peranan tanpa cawangan).
 *  - company: seluruh syarikat (tiada cawangan diperlukan).
 *  - flex:    versi universal - pentadbir pilih skop bagi setiap peranan: own (cawangan sendiri) | all (semua) | selected.
 *
 * `implemented` = false: dijadualkan tetapi belum dibina - tidak disenaraikan di halaman tetapan.
 */
class HomeModules
{
    public const KIND_SECTION = 'section';

    public const KIND_PANEL = 'panel';

    public const KIND_PINNED = 'pinned';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_FLEX = 'flex';

    public const MODE_OWN = 'own';

    public const MODE_ALL = 'all';

    public const MODE_SELECTED = 'selected';

    /**
     * @return array<string, array{key: string, title: string, icon: string, kind: string, scope: string, tab: bool, description: string, implemented: bool}>
     */
    public static function all(): array
    {
        $defs = [
            ['hq_directions', 'Arahan HQ', 'megaphone', self::KIND_PINNED, self::SCOPE_BRANCH, false, 'Arahan daripada HQ untuk cawangan; tekan "Faham".'],
            ['branch', 'Cawangan Anda', 'store', self::KIND_SECTION, self::SCOPE_BRANCH, true, 'Jualan & buyback semalam, permintaan stok, barang dalam transit.'],
            ['checklist', 'Checklist', 'clipboard-check', self::KIND_PANEL, self::SCOPE_BRANCH, true, 'Daily Checklist cawangan.'],
            ['target', 'Sasaran', 'target', self::KIND_PANEL, self::SCOPE_BRANCH, true, 'Sasaran bulanan RM & gram vs jualan sebenar.'],
            ['ranking', 'Ranking Staf', 'trophy', self::KIND_PANEL, self::SCOPE_BRANCH, true, 'Ranking jualan staf cawangan.'],
            ['rearrange', 'Status Barang', 'arrow-right-left', self::KIND_PANEL, self::SCOPE_BRANCH, true, 'Design perlu dihantar / diterima antara cawangan.'],
            ['stock', 'Jualan & Stok', 'gem', self::KIND_PANEL, self::SCOPE_BRANCH, true, 'Terlaris dan perlu restock.'],
            ['ceo_sales', 'Jualan Semalam', 'trending-up', self::KIND_SECTION, self::SCOPE_COMPANY, true, 'Jualan, nilai jualan dan hedge semalam (syarikat).'],
            ['ceo_finance', 'Kewangan & Stok Emas', 'landmark', self::KIND_SECTION, self::SCOPE_COMPANY, true, 'Aset, tunai, hutang, Used Gold, physical vs book.'],
            ['ceo_alerts', 'Perlu Perhatian', 'bell', self::KIND_SECTION, self::SCOPE_COMPANY, true, 'Amaran utama yang memerlukan tindakan.'],
            // Modul universal (skop dipilih pentadbir: cawangan sendiri / semua / cawangan dipilih) - boleh dipakai peranan tanpa cawangan.
            ['u_summary', 'Ringkasan Cawangan', 'building', self::KIND_PANEL, self::SCOPE_FLEX, true, 'Jualan & buyback semalam, permintaan stok dan transit - satu baris setiap cawangan.'],
            ['u_target', 'Sasaran Cawangan', 'crosshair', self::KIND_PANEL, self::SCOPE_FLEX, true, 'Sasaran bulanan vs jualan sebenar bagi setiap cawangan.'],
            ['u_ranking', 'Ranking Staf (Cawangan)', 'users', self::KIND_PANEL, self::SCOPE_FLEX, true, 'Ranking jualan staf merentas cawangan.'],
            ['u_stock', 'Jualan & Stok (Cawangan)', 'boxes', self::KIND_PANEL, self::SCOPE_FLEX, true, 'Terlaris dan perlu restock bagi gabungan cawangan.'],
        ];

        $modules = [];

        foreach ($defs as [$key, $title, $icon, $kind, $scope, $tab, $description]) {
            $modules[$key] = compact('key', 'title', 'icon', 'kind', 'scope', 'tab', 'description') + ['implemented' => true];
        }

        return $modules;
    }

    /** @return array{key: string, title: string, icon: string, kind: string, scope: string, tab: bool, description: string, implemented: bool}|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Kunci modul yang sudah dibina (urutan daftar). @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $m) => $m['implemented']));
    }

    /** Kunci modul jenis `section` (kelas SummarySection) -> kelas. @return array<string, class-string<SummarySection>> */
    public static function sectionClasses(): array
    {
        $map = [];

        foreach (HomeSummary::SECTIONS as $class) {
            $map[app($class)->key()] = $class;
        }

        return $map;
    }
}
