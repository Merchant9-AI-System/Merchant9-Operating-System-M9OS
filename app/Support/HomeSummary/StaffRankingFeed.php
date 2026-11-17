<?php

namespace App\Support\HomeSummary;

use App\Models\BranchMonthlyTarget;
use App\Models\HqDirection;

/**
 * Data panel "Ranking Jualan Staf" pd /home ketua cawangan: staf CAWANGAN SENDIRI sahaja, bulan ini (sehingga semalam)
 * atau bulan lepas (penuh). Jualan JEMiSys diambil utk semua cawangan dlm satu query berkumpul yg dicache dan
 * dikongsi - data cawangan lain tidak pernah dimasukkan ke dalam hasil.
 */
class StaffRankingFeed
{
    public const PERIOD_THIS = 'this';

    public const PERIOD_LAST = 'last';

    public function __construct(private StaffSales $sales) {}

    public static function normalizePeriod(?string $period): string
    {
        return $period === self::PERIOD_LAST ? self::PERIOD_LAST : self::PERIOD_THIS;
    }

    /** @return array<string, mixed> */
    public function forStore(string $store, string $period): array
    {
        $period = self::normalizePeriod($period);
        $current = BranchMonthlyTarget::currentMonth();
        $month = $period === self::PERIOD_LAST ? $current->copy()->subMonth() : $current;
        $through = $period === self::PERIOD_LAST ? $month->copy()->endOfMonth()->startOfDay() : HqDirection::today()->subDay();

        $rows = $this->sales->forMonth($month, $through);

        return [
            'period' => $period,
            'month_label' => $month->locale('ms')->translatedFormat('F Y'),
            'through' => $through->lt($month) ? null : $through->format('d/m/Y'),
            'available' => $rows !== null,
            ...($rows === null
                ? ['total' => null, 'rows' => []]
                : StaffSalesRanking::build($rows, $store)),
        ];
    }
}
