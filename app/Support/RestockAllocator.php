<?php

namespace App\Support;

/**
 * Agihan Qty order Restock ke setiap cawangan (Restock SAHAJA - unit Rearrange tak dikira).
 *
 * Peraturan (sama persis dgn salinan TypeScript dlm Pages/BackOfficeActions/Index.vue - ubah KEDUA-DUA):
 * 1. Cawangan yg REQUEST dahulu: setiap cawangan dapat unit sehingga permintaannya dipenuhi.
 * 2. Kemudian isi baki kekurangan ke paras (need - requested).
 * 3. Kalau Qty melebihi keperluan: lebihan diratakan satu unit bergilir ke SEMUA cawangan (ikut kod).
 * Dlm fasa 1 & 2, unit diberi satu-satu bergilir; cawangan dgn baki keperluan terbesar dahulu (kemudian kod).
 *
 * @phpstan-type BranchRow array{store_code: string, need: int, requested: int}
 */
final class RestockAllocator
{
    /**
     * @param  array<int, array{store_code: string, need: int, requested: int}>  $rows  baris plan (restockPlan()['branches'])
     * @return array<string, int> store_code => unit
     */
    public static function allocate(array $rows, int $qty): array
    {
        $allocation = [];
        $requestedCap = [];
        $fillCap = [];

        foreach ($rows as $row) {
            $store = (string) $row['store_code'];
            $allocation[$store] = 0;
            $requestedCap[$store] = min((int) $row['requested'], (int) $row['need']);
            $fillCap[$store] = max(0, (int) $row['need'] - (int) $row['requested']);
        }

        $left = max(0, $qty);
        $left = self::spread($allocation, $requestedCap, $left);
        $left = self::spread($allocation, $fillCap, $left);

        if ($left > 0 && $allocation !== []) {
            $stores = array_keys($allocation);
            sort($stores, SORT_STRING);

            for ($i = 0; $left > 0; $i++, $left--) {
                $allocation[$stores[$i % count($stores)]]++;
            }
        }

        return $allocation;
    }

    /**
     * Beri unit satu-satu bergilir kpd cawangan yg masih ada baki keperluan.
     *
     * @param  array<string, int>  $allocation  diubah terus
     * @param  array<string, int>  $caps  store_code => keperluan fasa ini
     * @return int baki unit yg tak habis diagih
     */
    private static function spread(array &$allocation, array $caps, int $left): int
    {
        $remaining = array_filter($caps, fn (int $cap) => $cap > 0);

        while ($left > 0 && $remaining !== []) {
            $stores = array_keys($remaining);
            usort($stores, fn (string $a, string $b) => [$remaining[$b], $a] <=> [$remaining[$a], $b]);

            foreach ($stores as $store) {
                if ($left === 0) {
                    break;
                }

                $allocation[$store]++;
                $left--;

                if (--$remaining[$store] === 0) {
                    unset($remaining[$store]);
                }
            }
        }

        return $left;
    }
}
