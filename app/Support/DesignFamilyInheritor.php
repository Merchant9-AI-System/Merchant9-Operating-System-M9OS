<?php

namespace App\Support;

use App\Models\InventoryMirror;
use Illuminate\Support\Facades\Log;

/**
 * Laksanakan DesignFamily::plan() ke jadual cermin: isi nickname/gambar kod yg kosong drpd adik-beradik dlm keluarga reka bentuk
 * yg sama (hanya bila sependapat) & bersihkan nilai warisan yg tak lagi sah. Idempotent - selamat dijalankan berulang (selepas
 * SyncMerchantNicknamesAndImages, atau terus via `php artisan merchant:inherit-family`).
 *
 * Baca kod MENTAH (berpadding - InternalCode ialah CHAR tetap) utk WHERE kemas kini supaya padanan tepat guna index
 * InternalCode, bukan TRIM() (rujuk dokblok SyncMerchantNicknamesAndImages).
 */
class DesignFamilyInheritor
{
    /**
     * @return array{updates: array<string, array<string, mixed>>, nickname_filled: int, image_filled: int, cleared: int}
     */
    public function run(bool $dryRun = false): array
    {
        $rawByCode = [];
        $rows = [];

        InventoryMirror::query()
            ->whereNotNull('InternalCode')
            ->where('InternalCode', '!=', '')
            ->selectRaw('InternalCode as raw_code, MAX(nickname) as nickname, MAX(image_url) as image_url, MAX(CategoryCode) as category, MAX(nickname_source) as nickname_source, MAX(image_source) as image_source')
            ->groupBy('InternalCode')
            ->toBase()
            ->get()
            ->each(function ($r) use (&$rawByCode, &$rows) {
                $code = trim((string) $r->raw_code);
                $rawByCode[$code] = $r->raw_code;
                $rows[] = [
                    'code' => $code,
                    'nickname' => $r->nickname,
                    'image_url' => $r->image_url,
                    'category' => $r->category,
                    'nickname_source' => $r->nickname_source,
                    'image_source' => $r->image_source,
                ];
            });

        $updates = DesignFamily::plan($rows);

        $nicknameFilled = 0;
        $imageFilled = 0;
        $cleared = 0;

        foreach ($updates as $code => $fields) {
            $nicknameFilled += isset($fields['nickname']) ? 1 : 0;
            $imageFilled += isset($fields['image_url']) ? 1 : 0;
            $cleared += (array_key_exists('nickname', $fields) && $fields['nickname'] === null) || (array_key_exists('image_url', $fields) && $fields['image_url'] === null) ? 1 : 0;

            if (! $dryRun) {
                InventoryMirror::where('InternalCode', $rawByCode[$code])->update($fields);
            }
        }

        if (! $dryRun) {
            Log::info("DesignFamilyInheritor: {$nicknameFilled} nickname & {$imageFilled} gambar diwarisi, {$cleared} kod dibersihkan ({$this->count($updates)} kod berubah).");
        }

        return ['updates' => $updates, 'nickname_filled' => $nicknameFilled, 'image_filled' => $imageFilled, 'cleared' => $cleared];
    }

    /** @param  array<string, mixed>  $updates */
    private function count(array $updates): int
    {
        return count($updates);
    }
}
