<?php

namespace App\Support\HomeSummary;

use App\Models\HqDirection;

/**
 * Arahan HQ yang patut dipaparkan pd /home ketua cawangan:
 * - berkuat kuasa hari ini (waktu Malaysia) dan menyasarkan cawangan ini (semua cawangan, atau cawangan dipilih);
 * - BELUM diakui -> kelihatan; SUDAH diakui -> kelihatan pada hari akuan sahaja, kemudian hilang.
 * Isi arahan (HTML rich editor) dihantar SUDAH DISANITASI (HqDirection::htmlBody()).
 */
class HqDirectionFeed
{
    /** @return array<int, array<string, mixed>> */
    public function forStore(string $store): array
    {
        $store = HqDirection::normalizeStore($store);
        $today = HqDirection::today();

        return HqDirection::query()
            ->activeOn($today)
            ->forStore($store)
            ->with(['acknowledgements' => fn ($q) => $q->where('store_code', $store)])
            ->latest()
            ->get()
            ->map(function (HqDirection $direction) use ($today) {
                $ack = $direction->acknowledgements->first();

                if ($ack !== null && ! $ack->isFromDay($today)) {
                    return null;   // diakui hari lain -> sudah tamat tempoh paparan
                }

                return [
                    'id' => $direction->id,
                    'title' => $direction->title,
                    'html' => $direction->htmlBody(),
                    'effective_from' => $direction->effective_from->toDateString(),
                    'effective_to' => $direction->effective_to?->toDateString(),
                    'created_by' => $direction->created_by_name,
                    'created_at' => $direction->created_at->copy()->setTimezone(HqDirection::TIMEZONE)->format('d/m/Y H:i'),
                    'acknowledged' => $ack !== null,
                    'acknowledged_at' => $ack?->acknowledged_at->copy()->setTimezone(HqDirection::TIMEZONE)->format('d/m H:i'),
                    'acknowledged_by' => $ack?->acknowledged_by_name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
