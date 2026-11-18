<?php

namespace App\Support\HomeSummary;

use App\Models\BranchMonthlyTarget;
use App\Models\HqDirection;

/**
 * "Sasaran Cawangan" universal: sasaran bulan semasa (RM & gram) vs jualan sebenar (sehingga semalam) bagi SETIAP cawangan
 * dlm skop - versi banyak cawangan bagi panel Sasaran Bulanan. Paparan sahaja (sasaran ditetapkan di Filament atau oleh
 * ketua cawangan). Cawangan tanpa sasaran ditandakan (has_target false) dan tak dikira dlm jumlah.
 */
class UniversalTargetFeed
{
    public function __construct(private MonthlyActuals $actuals) {}

    /**
     * @param  array<int, string>|null  $stores
     * @return array<string, mixed>
     */
    public function forStores(?array $stores): array
    {
        $scope = StoreScope::stores($stores);
        $month = BranchMonthlyTarget::currentMonth();
        $through = HqDirection::today()->subDay();

        $targets = BranchMonthlyTarget::query()
            ->whereDate('month', $month->toDateString())
            ->get()
            ->keyBy(fn (BranchMonthlyTarget $t) => BranchMonthlyTarget::normalizeStore($t->store_code));

        $actuals = $this->actuals->forMonth($month, $through);

        $rows = [];
        $totals = ['target_rm' => 0.0, 'actual_rm' => 0.0, 'target_grams' => 0.0, 'actual_grams' => 0.0, 'with_target' => 0, 'missing' => 0];

        foreach ($scope as $store) {
            $target = $targets->get($store);
            $actual = $actuals === null ? null : ($actuals[$store] ?? ['rm' => 0.0, 'grams' => 0.0]);
            $progress = $target !== null && $actual !== null
                ? TargetProgress::compute(['rm' => (float) $target->target_rm, 'grams' => (float) $target->target_grams], $actual, $month, $through)
                : null;

            if ($target === null) {
                $totals['missing']++;
            } else {
                $totals['with_target']++;
                $totals['target_rm'] += (float) $target->target_rm;
                $totals['target_grams'] += (float) $target->target_grams;
                $totals['actual_rm'] += $actual['rm'] ?? 0.0;
                $totals['actual_grams'] += $actual['grams'] ?? 0.0;
            }

            $rows[] = [
                'store' => $store,
                'has_target' => $target !== null,
                'target_rm' => $target === null ? null : (float) $target->target_rm,
                'actual_rm' => $actual['rm'] ?? null,
                'pct_rm' => $progress['rm']['pct'] ?? null,
                'on_track_rm' => $progress['rm']['on_track'] ?? null,
                'target_grams' => $target === null ? null : (float) $target->target_grams,
                'actual_grams' => $actual['grams'] ?? null,
                'pct_grams' => $progress['grams']['pct'] ?? null,
                'on_track_grams' => $progress['grams']['on_track'] ?? null,
            ];
        }

        // Kemajuan RM tertinggi dahulu; tanpa sasaran di hujung.
        usort($rows, fn (array $a, array $b) => [$b['pct_rm'] !== null, $b['pct_rm'] ?? -1, $a['store']] <=> [$a['pct_rm'] !== null, $a['pct_rm'] ?? -1, $b['store']]);

        $pct = fn (float $actual, float $target): ?float => $target > 0 ? round($actual / $target * 100, 1) : null;

        return [
            'available' => $actuals !== null,
            'month_label' => $month->locale('ms')->translatedFormat('F Y'),
            'through' => $through->lt($month) ? null : $through->format('d/m/Y'),
            'scope_label' => StoreScope::label($stores),
            'totals' => [
                'target_rm' => round($totals['target_rm'], 2),
                'actual_rm' => round($totals['actual_rm'], 2),
                'pct_rm' => $pct($totals['actual_rm'], $totals['target_rm']),
                'target_grams' => round($totals['target_grams'], 2),
                'actual_grams' => round($totals['actual_grams'], 2),
                'pct_grams' => $pct($totals['actual_grams'], $totals['target_grams']),
                'with_target' => $totals['with_target'],
                'missing' => $totals['missing'],
            ],
            'rows' => $rows,
        ];
    }
}
