<?php

namespace App\Support\HomeSummary;

use App\Models\BranchMonthlyTarget;
use App\Models\HqDirection;

/**
 * "Ranking Staf" universal: ranking jualan staf merentas cawangan dlm skop (semua / dipilih), bulan ini (sehingga semalam)
 * atau bulan lepas (penuh) - sumber & peraturan sama dgn StaffRankingFeed (satu query JEMiSys berkumpul, dicache).
 */
class UniversalRankingFeed
{
    public function __construct(private StaffSales $sales) {}

    /**
     * @param  array<int, string>|null  $stores
     * @return array<string, mixed>
     */
    public function forStores(?array $stores, string $period): array
    {
        $period = StaffRankingFeed::normalizePeriod($period);
        $current = BranchMonthlyTarget::currentMonth();
        $month = $period === StaffRankingFeed::PERIOD_LAST ? $current->copy()->subMonth() : $current;
        $through = $period === StaffRankingFeed::PERIOD_LAST ? $month->copy()->endOfMonth()->startOfDay() : HqDirection::today()->subDay();

        $rows = $this->sales->forMonth($month, $through);

        return [
            'period' => $period,
            'month_label' => $month->locale('ms')->translatedFormat('F Y'),
            'through' => $through->lt($month) ? null : $through->format('d/m/Y'),
            'scope_label' => StoreScope::label($stores),
            'available' => $rows !== null,
            ...($rows === null
                ? ['total' => null, 'rows' => []]
                : StaffSalesRanking::buildForStores($rows, StoreScope::stores($stores))),
        ];
    }
}
