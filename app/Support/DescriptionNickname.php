<?php

namespace App\Support;

/**
 * Nickname SANDARAN drpd jemisys_inventory_mirror.DiamondDesc (perihalan JEMiSys, cth. "Rantai Tangan Gajah Pasir Kosong")
 * utk kod yg masih tiada nickname selepas padanan laman web & warisan keluarga (App\Support\DesignFamily).
 *
 * Hanya NAMA - DiamondDesc dikongsi banyak reka bentuk berbeza (disahkan: ~63% kumpulan DiamondDesc yg sama ada >1 gambar
 * berbeza), jadi TIDAK PERNAH dipakai utk gambar. Ditanda nickname_source = 'desc' supaya nama laman web / keluarga
 * menggantikannya bila ada (DesignFamily::plan & SyncMerchantNicknamesAndImages).
 */
final class DescriptionNickname
{
    private const MIN_LENGTH = 4;

    /** Perihalan dalaman yg bukan nama produk. */
    private const IGNORED = '/scrap/i';

    /** Perihalan -> nickname bersih, atau null jika tak sesuai. Huruf kecil sepenuhnya -> Title Case. */
    public static function clean(?string $description): ?string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $description));

        if (mb_strlen($name) < self::MIN_LENGTH || preg_match('/\p{L}/u', $name) !== 1 || preg_match(self::IGNORED, $name) === 1) {
            return null;
        }

        return $name === mb_strtolower($name) ? mb_convert_case($name, MB_CASE_TITLE) : $name;
    }

    /**
     * Kod tanpa nickname -> nickname drpd perihalan; nama sandaran lama dikemas kini bila perihalan berubah.
     *
     * @param  array<int|string, array{code: string, nickname: ?string, description: ?string, nickname_source?: ?string}>  $rows
     * @return array<string, array{nickname: ?string, nickname_source: ?string}> kod => medan berubah
     */
    public static function plan(array $rows): array
    {
        $updates = [];

        foreach ($rows as $row) {
            $name = self::clean($row['description'] ?? null);
            $isFallback = ($row['nickname_source'] ?? null) === DesignFamily::SOURCE_DESCRIPTION;

            if ($name === null) {
                continue;
            }

            if (blank($row['nickname']) || ($isFallback && $row['nickname'] !== $name)) {
                $updates[$row['code']] = ['nickname' => $name, 'nickname_source' => DesignFamily::SOURCE_DESCRIPTION];
            }
        }

        return $updates;
    }
}
