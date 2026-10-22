<?php

namespace App\Support;

/**
 * Keluarga reka bentuk drpd KOD design (InternalCode 12 aksara) - utk mewarisi nickname & gambar antara kod yg hanya
 * berbeza JULAT BERAT.
 *
 * Struktur kod (disahkan drpd data & pengguna), cth. RTATAU04K10G:
 *   RT      awalan baris produk (BUKAN CategoryCode JEMiSys - cuma ~55% sama; CF/CB/CM semuanya kategori CE)
 *   ATAU    gaya (dikongsi antara awalan, jadi gaya sahaja bukan identiti reka bentuk)
 *   04      versi/nombor reka bentuk ('00' = versi asal, sah)
 *   K       jenis binaan: K Kosong, H Separa Padu, P Padu, G Hard Gold, S Stamping (barang berbeza -> kekal dlm kunci)
 *   10      julat berat (05, 10, 20, 50 ... ~had atas berat dlm gram) - SATU-SATUNYA segmen yg dibuang drpd kunci
 *   G       kemasan: G Full Gold, C Chrome (rupa berbeza -> kekal dlm kunci)
 *
 * Kunci keluarga = awalan+gaya+nombor+binaan+kemasan (julat berat dibuang). Peraturan warisan KONSERVATIF kerana ~27%
 * keluarga ada nama berbeza (julat berat lebih tinggi kadangkala versi lain, cth. "Lintah Wave" vs "Lintah Dimensi Stripes"):
 * nilai hanya diwarisi bila SEMUA adik-beradik yg ada nilai SEPENDAPAT (nama teras sama, gambar sama) & berkongsi CategoryCode.
 * GAMBAR tambahan: tak diwarisi langsung bila nama adik-beradik bercanggah (gambar seragam sahaja mungkin gambar produk lain).
 * Versi '00' yg adik-beradiknya bercanggah: ambil nama/gambar adik-beradik berat TERDEKAT (sumber 'nearest', bukan padanan tepat).
 */
final class DesignFamily
{
    private const PATTERN = '/^([A-Z]{2})([A-Z]{4})(\d{2})([A-Z])(\d{2})([A-Z])$/';

    public const SOURCE_FAMILY = 'family';

    public const SOURCE_NEAREST = 'nearest';

    /** Nickname sandaran drpd DiamondDesc (hanya nama; App\Support\DescriptionNickname). */
    public const SOURCE_DESCRIPTION = 'desc';

    /** Julat berat terjauh (unit kod, cth. 35 -> 45 = 10) yg masih dikira "terdekat". */
    private const NEAREST_MAX_WEIGHT_GAP = 15;

    /** @return array{prefix: string, style: string, design: string, build: string, weight: string, finish: string}|null */
    public static function parse(string $code): ?array
    {
        if (preg_match(self::PATTERN, strtoupper(trim($code)), $m) !== 1) {
            return null;
        }

        return ['prefix' => $m[1], 'style' => $m[2], 'design' => $m[3], 'build' => $m[4], 'weight' => $m[5], 'finish' => $m[6]];
    }

    /**
     * Kunci keluarga (julat berat dibuang), atau null jika kod tak berformat. '00' = versi asal (sah sbg keluarga), termasuk
     * gaya generik 'XX' (diputuskan pengguna) - identiti lebih lemah, jadi peraturan sepakat/terdekat tetap melindungi.
     */
    public static function familyKey(string $code): ?string
    {
        $parts = self::parse($code);

        if ($parts === null) {
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
     * Nilai paling hampir (julat berat terdekat) drpd adik-beradik ASLI yg ada nilai itu. Seri -> berat lebih rendah. Null
     * jika tiada dlm lingkungan NEAREST_MAX_WEIGHT_GAP.
     *
     * @param  array{code: string}  $target
     * @param  list<array<string, mixed>>  $sources
     */
    private static function nearest(array $target, array $sources, string $field): ?string
    {
        $targetWeight = (int) (self::parse($target['code'])['weight'] ?? 0);
        $best = null;

        foreach ($sources as $source) {
            $weight = (int) (self::parse($source['code'])['weight'] ?? 0);
            $gap = abs($weight - $targetWeight);

            if ($source['code'] === $target['code'] || $gap > self::NEAREST_MAX_WEIGHT_GAP) {
                continue;
            }

            if ($best === null || $gap < $best['gap'] || ($gap === $best['gap'] && $weight < $best['weight'])) {
                $best = ['gap' => $gap, 'weight' => $weight, 'value' => $source[$field]];
            }
        }

        return $best === null ? null : (string) $best['value'];
    }

    private static function isInherited(?string $source): bool
    {
        return in_array($source, [self::SOURCE_FAMILY, self::SOURCE_NEAREST, self::SOURCE_DESCRIPTION], true);
    }

    /**
     * Rancang kemas kini warisan. Pulangkan hanya kod yg BERUBAH.
     *
     * Dua peringkat: (1) 'family' - nilai sepunya bila adik-beradik sependapat; (2) 'nearest' - hanya utk versi '00' yg adik-
     * beradiknya bercanggah: nama & gambar adik-beradik berat TERDEKAT (bukan padanan tepat, jadi ditanda berasingan).
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

            $isVersionZero = (self::parse($members[0]['code'])['design'] ?? null) === '00';
            $categories = array_unique(array_filter(array_map(fn (array $m) => trim((string) ($m['category'] ?? '')), $members)));
            $sameCategory = count($categories) <= 1;

            // Sumber = nilai ASLI sahaja (jangan mewarisi nilai yg sendiri diwarisi - elak rantai).
            $nameSources = array_values(array_filter($members, fn (array $m) => filled($m['nickname']) && ! self::isInherited($m['nickname_source'] ?? null)));
            $imageSources = array_values(array_filter($members, fn (array $m) => filled($m['image_url']) && ! self::isInherited($m['image_source'] ?? null)));

            $nameCores = count(array_unique(array_map(fn (array $m) => self::coreName($m['nickname']), $nameSources)));

            $sharedName = null;
            if ($sameCategory && $nameCores === 1) {
                $sharedName = self::inheritableName($nameSources[0]['nickname']);
            }

            // Gambar sepunya hanya bila NAMA adik-beradik juga tak bercanggah (>1 nama teras = produk berbeza dlm keluarga yg
            // sama, cth. "Kait 2 Polish" vs "Kait 3 Dimensi") - gambar seragam sahaja tak cukup, ia mungkin gambar produk lain.
            $sharedImage = null;
            if ($sameCategory && $nameCores <= 1 && $imageSources !== [] && count(array_unique(array_map(fn (array $m) => $m['image_url'], $imageSources))) === 1) {
                $sharedImage = $imageSources[0]['image_url'];
            }

            $useNearest = $sameCategory && $isVersionZero;

            foreach ($members as $m) {
                $change = [];

                // Nama & gambar yg patut dimiliki kod ini: [nilai, sumber] atau [null, null].
                $name = [null, null];
                if ($sharedName !== null) {
                    $name = [$sharedName, self::SOURCE_FAMILY];
                } elseif ($useNearest && ($nearestName = self::nearest($m, $nameSources, 'nickname')) !== null) {
                    $name = [self::inheritableName($nearestName), self::SOURCE_NEAREST];
                }

                $image = [null, null];
                if ($sharedImage !== null) {
                    $image = [$sharedImage, self::SOURCE_FAMILY];
                } elseif ($useNearest && ($nearestImage = self::nearest($m, $imageSources, 'image_url')) !== null) {
                    $image = [$nearestImage, self::SOURCE_NEAREST];
                }

                // Isi bila kosong; kemas kini/bersihkan HANYA nilai yg sendiri diwarisi. Nilai asli tak disentuh.
                if (blank($m['nickname']) && $name[0] !== null) {
                    $change += ['nickname' => $name[0], 'nickname_source' => $name[1]];
                } elseif (self::isInherited($m['nickname_source'] ?? null)
                    && ($m['nickname'] !== $name[0] || $m['nickname_source'] !== $name[1])
                    // Nama sandaran DiamondDesc hanya diganti bila ada nilai keluarga yg lebih baik - tak dibersihkan.
                    && ($m['nickname_source'] !== self::SOURCE_DESCRIPTION || $name[0] !== null)) {
                    $change += ['nickname' => $name[0], 'nickname_source' => $name[1]];
                }

                if (blank($m['image_url']) && $image[0] !== null) {
                    $change += ['image_url' => $image[0], 'image_source' => $image[1]];
                } elseif (self::isInherited($m['image_source'] ?? null) && ($m['image_url'] !== $image[0] || $m['image_source'] !== $image[1])) {
                    $change += ['image_url' => $image[0], 'image_source' => $image[1]];
                }

                if ($change !== []) {
                    $updates[$m['code']] = $change;
                }
            }
        }

        return $updates;
    }
}
