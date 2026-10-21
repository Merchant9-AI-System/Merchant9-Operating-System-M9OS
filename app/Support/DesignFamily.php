<?php

namespace App\Support;

/**
 * Keluarga reka bentuk drpd KOD design (InternalCode 12 aksara) - utk mewarisi nickname & gambar antara kod yg hanya
 * berbeza JULAT BERAT.
 *
 * Struktur kod (disahkan drpd data & pengguna), cth. RTATAU04K10G:
 *   RT      awalan baris produk (BUKAN CategoryCode JEMiSys - cuma ~55% sama; CF/CB/CM semuanya kategori CE)
 *   ATAU    gaya (dikongsi antara awalan, jadi gaya sahaja bukan identiti reka bentuk)
 *   04      nombor reka bentuk ('00' = tiada nombor -> BUKAN identiti, dikecualikan)
 *   K       jenis binaan: K Kosong, H Separa Padu, P Padu, G Hard Gold, S Stamping (barang berbeza -> kekal dlm kunci)
 *   10      julat berat (05, 10, 20, 50 ... ~had atas berat dlm gram) - SATU-SATUNYA segmen yg dibuang drpd kunci
 *   G       kemasan: G Full Gold, C Chrome (rupa berbeza -> kekal dlm kunci)
 *
 * Kunci keluarga = awalan+gaya+nombor+binaan+kemasan (julat berat dibuang). Peraturan warisan KONSERVATIF kerana ~27%
 * keluarga ada nama berbeza (julat berat lebih tinggi kadangkala versi lain, cth. "Lintah Wave" vs "Lintah Dimensi Stripes"):
 * nilai hanya diwarisi bila SEMUA adik-beradik yg ada nilai SEPENDAPAT (nama teras sama, gambar sama) & berkongsi CategoryCode.
 * GAMBAR tambahan: tak diwarisi langsung bila nama adik-beradik bercanggah (gambar seragam sahaja mungkin gambar produk lain).
 */
final class DesignFamily
{
    private const PATTERN = '/^([A-Z]{2})([A-Z]{4})(\d{2})([A-Z])(\d{2})([A-Z])$/';

    public const SOURCE_FAMILY = 'family';

    /** @return array{prefix: string, style: string, design: string, build: string, weight: string, finish: string}|null */
    public static function parse(string $code): ?array
    {
        if (preg_match(self::PATTERN, strtoupper(trim($code)), $m) !== 1) {
            return null;
        }

        return ['prefix' => $m[1], 'style' => $m[2], 'design' => $m[3], 'build' => $m[4], 'weight' => $m[5], 'finish' => $m[6]];
    }

    /** Kunci keluarga (julat berat dibuang), atau null jika kod tak berformat / tiada nombor reka bentuk ('00'). */
    public static function familyKey(string $code): ?string
    {
        $parts = self::parse($code);

        if ($parts === null || $parts['design'] === '00') {
            return null;
        }

        return $parts['prefix'].$parts['style'].$parts['design'].$parts['build'].'|'.$parts['finish'];
    }

    /** Nama teras utk banding: tanpa akhiran dimensi "L:1.1CM", huruf kecil, ruang diringkaskan. */
    public static function coreName(?string $nickname): string
    {
        $name = mb_strtolower(trim((string) $nickname));
        $name = preg_replace('/\bl:\s*[\d.]+\s*cm\b/i', '', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /** Nama diwarisi: nama sumber tanpa akhiran "L:..CM" (panjang boleh berbeza antara julat berat), kes asal dikekalkan. */
    public static function inheritableName(string $nickname): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\bl:\s*[\d.]+\s*cm\b/i', '', $nickname)));
    }

    /**
     * Rancang kemas kini warisan. Pulangkan hanya kod yg BERUBAH.
     *
     * @param  array<int|string, array{code: string, nickname: ?string, image_url: ?string, category: ?string, nickname_source?: ?string, image_source?: ?string}>  $rows
     * @return array<string, array{nickname?: ?string, nickname_source?: ?string, image_url?: ?string, image_source?: ?string}> kod => medan berubah
     */
    public static function plan(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = self::familyKey($row['code']);

            if ($key !== null) {
                $groups[$key][] = $row;
            }
        }

        $updates = [];

        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            $categories = array_unique(array_filter(array_map(fn (array $m) => trim((string) ($m['category'] ?? '')), $members)));
            $sameCategory = count($categories) <= 1;

            // Sumber = nilai ASLI sahaja (jangan mewarisi nilai yg sendiri diwarisi - elak rantai).
            $nameSources = array_values(array_filter($members, fn (array $m) => filled($m['nickname']) && ($m['nickname_source'] ?? null) !== self::SOURCE_FAMILY));
            $imageSources = array_values(array_filter($members, fn (array $m) => filled($m['image_url']) && ($m['image_source'] ?? null) !== self::SOURCE_FAMILY));

            $nameCores = count(array_unique(array_map(fn (array $m) => self::coreName($m['nickname']), $nameSources)));

            $desiredName = null;
            if ($sameCategory && $nameCores === 1) {
                $desiredName = self::inheritableName($nameSources[0]['nickname']);
            }

            // Gambar hanya diwarisi bila NAMA adik-beradik juga tak bercanggah (>1 nama teras = produk berbeza dlm keluarga yg
            // sama, cth. "Kait 2 Polish" vs "Kait 3 Dimensi") - gambar seragam sahaja tak cukup, ia mungkin gambar produk lain
            // (disahkan drpd sampel dry-run: gambar "Kait 2 Polish" hampir diberi kpd "Kait 3 Dimensi").
            $desiredImage = null;
            if ($sameCategory && $nameCores <= 1 && $imageSources !== [] && count(array_unique(array_map(fn (array $m) => $m['image_url'], $imageSources))) === 1) {
                $desiredImage = $imageSources[0]['image_url'];
            }

            foreach ($members as $m) {
                $change = [];

                // Nickname: isi bila kosong; kemas kini/bersihkan HANYA nilai yg sendiri diwarisi.
                if (blank($m['nickname']) && $desiredName !== null) {
                    $change += ['nickname' => $desiredName, 'nickname_source' => self::SOURCE_FAMILY];
                } elseif (($m['nickname_source'] ?? null) === self::SOURCE_FAMILY && $m['nickname'] !== $desiredName) {
                    $change += ['nickname' => $desiredName, 'nickname_source' => $desiredName === null ? null : self::SOURCE_FAMILY];
                }

                if (blank($m['image_url']) && $desiredImage !== null) {
                    $change += ['image_url' => $desiredImage, 'image_source' => self::SOURCE_FAMILY];
                } elseif (($m['image_source'] ?? null) === self::SOURCE_FAMILY && $m['image_url'] !== $desiredImage) {
                    $change += ['image_url' => $desiredImage, 'image_source' => $desiredImage === null ? null : self::SOURCE_FAMILY];
                }

                if ($change !== []) {
                    $updates[$m['code']] = $change;
                }
            }
        }

        return $updates;
    }
}
