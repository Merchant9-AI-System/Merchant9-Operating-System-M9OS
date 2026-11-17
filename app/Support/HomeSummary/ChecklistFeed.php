<?php

namespace App\Support\HomeSummary;

use App\Models\ChecklistCompletion;
use App\Models\ChecklistItem;
use Illuminate\Support\Carbon;

/**
 * Daily Checklist cawangan pd /home ketua cawangan: item aktif yg terpakai utk cawangan ini + penanda siap HARI INI
 * (hari Malaysia). Tertunggak = belum siap dan waktu "sebelum" (due_time, waktu Malaysia) hari ini sudah lepas.
 * Selesai ditanda utk cawangan (bukan setiap staf), tiada bukti gambar.
 */
class ChecklistFeed
{
    /** @return array{date: string, total: int, done: int, overdue: int, items: array<int, array<string, mixed>>} */
    public function forStore(string $store, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now('Asia/Kuala_Lumpur'))->copy()->setTimezone('Asia/Kuala_Lumpur');
        $today = $now->copy()->startOfDay();

        $items = ChecklistItem::query()->active()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (ChecklistItem $item) => $item->appliesToStore($store))
            ->values();

        $completions = ChecklistCompletion::query()
            ->whereIn('checklist_item_id', $items->pluck('id'))
            ->where('store_code', $store)
            ->whereDate('date', $today->toDateString())
            ->get()
            ->keyBy('checklist_item_id');

        $rows = $items->map(function (ChecklistItem $item) use ($completions, $today, $now) {
            $completion = $completions->get($item->id);
            $due = $item->dueLabel();
            $overdue = $completion === null && $due !== null
                && $now->gt(Carbon::parse($today->toDateString().' '.$due, 'Asia/Kuala_Lumpur'));

            return [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'due' => $due,
                'done' => $completion !== null,
                'done_at' => $completion?->completed_at?->copy()->setTimezone('Asia/Kuala_Lumpur')->format('H:i'),
                'done_by' => $completion?->completed_by_name,
                'overdue' => $overdue,
            ];
        })->all();

        return [
            'date' => $today->format('d/m/Y'),
            'total' => count($rows),
            'done' => count(array_filter($rows, fn (array $r) => $r['done'])),
            'overdue' => count(array_filter($rows, fn (array $r) => $r['overdue'])),
            'items' => $rows,
        ];
    }
}
