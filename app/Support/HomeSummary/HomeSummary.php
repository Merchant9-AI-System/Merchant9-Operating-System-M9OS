<?php

namespace App\Support\HomeSummary;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Menyusun seksyen ringkasan semalam yang layak bagi seseorang pengguna (halaman /home). Pengguna tanpa
 * seksyen yang sepadan mendapat senarai kosong (halaman hanya paparkan pautan).
 */
class HomeSummary
{
    /** @var array<int, class-string<SummarySection>> */
    public const SECTIONS = [
        BranchSection::class,
        CeoSalesSection::class,
        CeoFinanceSection::class,
        CeoAlertsSection::class,
    ];

    /** Peranan yang sudah ada seksyen ringkasan (utk halaman tetapan Halaman Home). */
    public const ROLES_WITH_SUMMARY = [BranchSection::ROLE, ...CeoSectionBase::ROLES];

    /**
     * @param  array<int, string>|null  $keys  kunci seksyen yg dipasang (HomeLayout) - jika diberi, `appliesTo()` dilangkau
     *                                         (susun atur ditentukan pentadbir); null = ikut `appliesTo()` peranan (lama).
     * @return array<int, array<string, mixed>>
     */
    public function sectionsFor(User $user, Carbon $date, ?array $keys = null): array
    {
        $result = [];

        foreach (self::SECTIONS as $class) {
            /** @var SummarySection $section */
            $section = app($class);

            if ($keys === null ? ! $section->appliesTo($user) : ! in_array($section->key(), $keys, true)) {
                continue;
            }

            // array_merge (bukan "+"): hasil build() mesti MENIMPA nilai lalai list/note, bukan sebaliknya.
            $result[] = array_merge(
                ['list' => [], 'note' => null],
                ['key' => $section->key(), 'title' => $section->title()],
                $section->build($user, $date),
            );
        }

        return $result;
    }
}
