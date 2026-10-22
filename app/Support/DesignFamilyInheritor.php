<?php

namespace App\Support;

use App\Models\InventoryMirror;
use Illuminate\Support\Facades\Log;

/**
 * Laksanakan DesignFamily::plan() (warisan keluarga) kemudian DescriptionNickname::plan() (nickname sandaran DiamondDesc) ke jadual cermin: isi nickname/gambar kod yg kosong drpd adik-beradik dlm keluarga reka bentuk
 * yg sama (hanya bila sependapat) & bersihkan nilai warisan yg tak lagi sah. Idempotent - selamat dijalankan berulang (selepas
 * SyncMerchantNicknamesAndImages, atau terus via `php artisan merchant:inherit-family`).
 *
 * Baca kod MENTAH (berpadding - InternalCode ialah CHAR tetap) utk WHERE kemas kini supaya padanan tepat guna index
 * InternalCode, bukan TRIM() (rujuk dokblok SyncMerchantNicknamesAndImages).
 */
class DesignFamilyInheritor
{
    /**
     * @return array{updates: array<string, array<string, mixed>>, nickname_filled: int, image_filled: int, cleared: int, description_filled: int}
     */
    public function run(bool $dryRun = false): array
    {
        $rawByCode = [];
        $rows = [];

        InventoryMirror::query()
            ->whereNotNull('InternalCode')
            ->where('InternalCode', '!=', '')
            ->selectRaw('InternalCode as raw_code, MAX(nickname) as nickname, MAX(image_url) as image_url, MAX(CategoryCode) as category, MAX(DiamondDesc) as description, MAX(nickname_source) as nickname_source, MAX(image_source) as image_source')
            ->groupBy('InternalCode')
            ->toBase()
            ->get()
            ->each(function ($r) use (&$rawByCode, &$rows) {
                // Beberapa kod mentah boleh sama selepas trim (cth. aksara TAB di hujung) - gabung & kemas kini semua varian.
                $code = trim((string) $r->raw_code);
                $rawByCode[$code][] = $r->raw_code;
                $rows[$code] = [
                    'code' => $code,
                    'nickname' => $r->nickname ?? ($rows[$code]['nickname'] ?? null),
                    'image_url' => $r->image_url ?? ($rows[$code]['image_url'] ?? null),
                    'category' => $r->category ?? ($rows[$code]['category'] ?? null),
                    'description' => $r->description ?? ($rows[$code]['description'] ?? null),
                    'nickname_source' => $r->nickname_source ?? ($rows[$code]['nickname_source'] ?? null),
                    'image_source' => $r->image_source ?? ($rows[$code]['image_source'] ?? null),
                ];
            });

        $updates = DesignFamily::plan(array_values($rows));

        // Peringkat 2: kod yg MASIH tiada nickname (selepas peringkat 1) -> nama sandaran drpd DiamondDesc.
        $afterFamily = array_map(fn (array $row) => array_replace($row, $updates[$row['code']] ?? []), $rows);
        $descriptionUpdates = DescriptionNickname::plan(array_values($afterFamily));

        foreach ($descriptionUpdates as $code => $fields) {
            $updates[$code] = array_replace($updates[$code] ?? [], $fields);
        }

        $nicknameFilled = 0;
        $imageFilled = 0;
        $cleared = 0;
        $descriptionFilled = count($descriptionUpdates);

        foreach ($updates as $code => $fields) {
            $nicknameFilled += isset($fields['nickname']) && ($fields['nickname_source'] ?? null) !== DesignFamily::SOURCE_DESCRIPTION ? 1 : 0;
            $imageFilled += isset($fields['image_url']) ? 1 : 0;
            $cleared += (array_key_exists('nickname', $fields) && $fields['nickname'] === null) || (array_key_exists('image_url', $fields) && $fields['image_url'] === null) ? 1 : 0;

            if (! $dryRun) {
                InventoryMirror::whereIn('InternalCode', $rawByCode[$code])->update($fields);
            }
        }

        if (! $dryRun) {
            Log::info("DesignFamilyInheritor: {$nicknameFilled} nickname & {$imageFilled} gambar diwarisi, {$descriptionFilled} nickname sandaran DiamondDesc, {$cleared} kod dibersihkan ({$this->count($updates)} kod berubah).");
        }

        return ['updates' => $updates, 'nickname_filled' => $nicknameFilled, 'image_filled' => $imageFilled, 'cleared' => $cleared, 'description_filled' => $descriptionFilled];
    }

    /** @param  array<string, mixed>  $updates */
    private function count(array $updates): int
    {
        return count($updates);
    }
}
