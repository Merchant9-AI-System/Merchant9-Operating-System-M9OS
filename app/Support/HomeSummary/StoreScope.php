<?php

namespace App\Support\HomeSummary;

use App\Support\BranchActivityCalculator;

/**
 * Skop cawangan modul universal /home: `null` = semua cawangan, senarai = cawangan yg dibenarkan sahaja (rujuk
 * HomeLayout::forUser). Semua kod dinormalkan (trim + huruf besar) kerana JEMiSys & users.store_code sering berpad ruang.
 */
class StoreScope
{
    /**
     * Senarai cawangan sebenar dlm skop.
     *
     * @param  array<int, string>|null  $stores
     * @return array<int, string>
     */
    public static function stores(?array $stores): array
    {
        $normalize = fn (string $code): string => strtoupper(trim($code));

        if ($stores === null) {
            return array_values(array_unique(array_map($normalize, BranchActivityCalculator::branches())));
        }

        return array_values(array_unique(array_filter(array_map($normalize, $stores), fn (string $code) => $code !== '')));
    }

    /**
     * Label ringkas skop utk paparan: "Semua cawangan", "WM" atau "3 cawangan".
     *
     * @param  array<int, string>|null  $stores
     */
    public static function label(?array $stores): string
    {
        if ($stores === null) {
            return 'Semua cawangan';
        }

        $list = self::stores($stores);

        return match (true) {
            $list === [] => 'Tiada cawangan',
            count($list) === 1 => $list[0],
            default => count($list).' cawangan',
        };
    }
}
