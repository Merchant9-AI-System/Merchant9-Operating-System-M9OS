<?php

namespace App\Support;

/**
 * Pemetaan kod design -> produk laman web merchant9.com drpd senarai kod pada kad produk (teks kuning: satu produk, satu gambar,
 * beberapa kod design - cth. RT-030976 menyenaraikan RTOBBB05K10C, RTAUPA02K15C & RTAUPA02K10C). Kod design BUKAN pertama dalam
 * senarai tak boleh dicari terus di laman web, jadi pemetaan ini satu-satunya jalan ke gambarnya.
 *
 * Pemetaan eksplisit (diisi admin) lebih boleh dipercayai drpd kad hasil pertama tak disahkan (SyncMerchantNicknamesAndImages)
 * atau warisan keluarga (DesignFamily), jadi menggantikan nilai warisan/sandaran DAN nilai asli yg berbeza (cth. gambar lapuk).
 * Kod yg disenaraikan di bawah >1 produk dgn gambar berbeza = bercanggah -> dilangkau & dilaporkan.
 */
final class CatalogCodeMap
{
    /** Cara perubahan - utk laporan dry-run. */
    public const KIND_FILLED = 'filled';

    public const KIND_REPLACED_INHERITED = 'replaced_inherited';

    public const KIND_REPLACED_OWN = 'replaced_own';

    /**
     * @param  array<int, array{name: ?string, image_url: ?string, product_url: ?string, design_codes: array<int, string>}>  $cards
     * @param  array<string, array{nickname: ?string, image_url: ?string, nickname_source?: ?string, image_source?: ?string}>  $dbRows  kod (trim, UPPER) => nilai semasa
     * @return array{updates: array<string, array<string, ?string>>, kinds: array<string, string>, stats: array{codes_listed: int, in_db: int, conflicts: int, filled: int, replaced_inherited: int, replaced_own: int, unchanged: int}}
     */
    public static function plan(array $cards, array $dbRows): array
    {
        $byCode = [];

        foreach ($cards as $card) {
            if (blank($card['name'])) {
                continue;
            }

            foreach ($card['design_codes'] as $code) {
                $byCode[strtoupper(trim($code))][] = $card;
            }
        }

        $updates = [];
        $kinds = [];
        $stats = ['codes_listed' => count($byCode), 'in_db' => 0, 'conflicts' => 0, 'filled' => 0, 'replaced_inherited' => 0, 'replaced_own' => 0, 'unchanged' => 0];

        foreach ($byCode as $code => $codeCards) {
            if (! isset($dbRows[$code])) {
                continue;
            }

            $stats['in_db']++;

            // Produk berbeza (gambar atau nama berbeza) menyenaraikan kod yg SAMA -> tak pasti yg mana betul.
            $distinct = collect($codeCards)->map(fn (array $c) => ($c['image_url'] ?? '').'|'.mb_strtolower((string) $c['name']))->unique();

            if ($distinct->count() > 1) {
                $stats['conflicts']++;

                continue;
            }

            $card = $codeCards[0];
            $row = $dbRows[$code];
            $fields = [];

            if ($row['nickname'] !== $card['name'] || filled($row['nickname_source'] ?? null)) {
                $fields += ['nickname' => $card['name'], 'nickname_source' => null];
            }

            if (filled($card['image_url']) && ($row['image_url'] !== $card['image_url'] || filled($row['image_source'] ?? null))) {
                $fields += ['image_url' => $card['image_url'], 'image_source' => null];
            }

            if ($fields === []) {
                $stats['unchanged']++;

                continue;
            }

            $kind = self::kindOf($row, $fields);
            $updates[$code] = $fields;
            $kinds[$code] = $kind;
            $stats[$kind]++;
        }

        return ['updates' => $updates, 'kinds' => $kinds, 'stats' => $stats];
    }

    /**
     * Lebih berat dikira: nilai ASLI diganti > nilai warisan/sandaran diganti > baru diisi.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, ?string>  $fields
     */
    private static function kindOf(array $row, array $fields): string
    {
        $replacesOwn = (isset($fields['nickname']) && filled($row['nickname']) && blank($row['nickname_source'] ?? null) && $row['nickname'] !== $fields['nickname'])
            || (isset($fields['image_url']) && filled($row['image_url']) && blank($row['image_source'] ?? null) && $row['image_url'] !== $fields['image_url']);

        if ($replacesOwn) {
            return self::KIND_REPLACED_OWN;
        }

        $replacesInherited = (isset($fields['nickname']) && filled($row['nickname']) && filled($row['nickname_source'] ?? null))
            || (isset($fields['image_url']) && filled($row['image_url']) && filled($row['image_source'] ?? null));

        return $replacesInherited ? self::KIND_REPLACED_INHERITED : self::KIND_FILLED;
    }
}
