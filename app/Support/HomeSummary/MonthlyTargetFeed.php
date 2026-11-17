<?php

namespace App\Support\HomeSummary;

use App\Models\BranchMonthlyTarget;
use App\Models\HqDirection;

/**
 * Data panel "Sasaran Bulanan" pd /home ketua cawangan: sasaran (RM & gram) bulan semasa, jualan sebenar
 * bulan-ke-tarikh dan kemajuan (baki, purata harian, ramalan). Sebenar boleh null (JEMiSys tak dapat dicapai) -
 * sasaran tetap dipaparkan dan boleh dikemas kini.
 */
class MonthlyTargetFeed
{
    public function __construct(private MonthlyActuals $actuals) {}

    /** @return array<string, mixed> */
    public function forStore(string $store): array
    {
        $store = BranchMonthlyTarget::normalizeStore($store);
        $month = BranchMonthlyTarget::currentMonth();
        $through = HqDirection::today()->subDay();   // hari lengkap terakhir = semalam

        $target = BranchMonthlyTarget::findFor($store, $month);
        $actuals = $this->actuals->forMonth($month, $through);
        $actual = $actuals === null ? null : ($actuals[$store] ?? ['rm' => 0.0, 'grams' => 0.0]);

        return [
            'month' => $month->toDateString(),
            'month_label' => $month->locale('ms')->translatedFormat('F Y'),
            'through' => $through->lt($month) ? null : $through->format('d/m/Y'),
            'target' => $target === null ? null : [
                'rm' => (float) $target->target_rm,
                'grams' => (float) $target->target_grams,
                'remark' => $target->remark,
                'source' => $target->source,
                'set_by' => $target->set_by_name,
                'updated_at' => $target->updated_at->copy()->setTimezone(HqDirection::TIMEZONE)->format('d/m/Y H:i'),
            ],
            'actual' => $actual,
            'progress' => $target !== null && $actual !== null
                ? TargetProgress::compute(['rm' => (float) $target->target_rm, 'grams' => (float) $target->target_grams], $actual, $month, $through)
                : null,
            // Sasaran sedia ada -> kemas kini WAJIB bawa catatan.
            'requires_remark' => $target !== null,
        ];
    }
}
