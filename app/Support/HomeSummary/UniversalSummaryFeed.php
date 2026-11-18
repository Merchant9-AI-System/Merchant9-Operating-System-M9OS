<?php

namespace App\Support\HomeSummary;

use App\Enums\JemisysInventoryStatus;
use App\Models\BranchDemandRequest;
use App\Models\InventoryMirror;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Ringkasan Cawangan" universal: versi Cawangan Anda utk skop banyak cawangan (semua / dipilih) - jualan & buyback
 * SEMALAM (JEMiSys, laporan YesterdaySnapshot yg sama dgn Cawangan Anda), permintaan stok terbuka/lapuk dan barang dalam
 * transit (cermin). Satu baris setiap cawangan + jumlah. Laporan gagal -> `available` false (bukan 0).
 */
class UniversalSummaryFeed
{
    public function __construct(private YesterdaySnapshot $snapshot) {}

    /**
     * @param  array<int, string>|null  $stores  skop (null = semua)
     * @return array<string, mixed>
     */
    public function forStores(?array $stores): array
    {
        $date = YesterdaySnapshot::date();
        $report = $this->snapshot->report($date);
        $scope = StoreScope::stores($stores);

        $requests = $this->requests($scope);
        $transit = $this->transit($scope);
        $rows = [];

        foreach ($scope as $store) {
            $sales = ['qty' => 0.0, 'gold_weight' => 0.0, 'total_price' => 0.0, 'cost' => 0.0];

            foreach ($report['stores'][$store] ?? [] as $line) {
                foreach (array_keys($sales) as $key) {
                    $sales[$key] += (float) $line[$key];
                }
            }

            $rows[] = [
                'store' => $store,
                'rm' => round($sales['total_price'], 2),
                'pcs' => (int) round($sales['qty']),
                'grams' => round($sales['gold_weight'], 2),
                'cost' => $sales['cost'],
                'buyback_grams' => (float) ($report['buyback_by_store'][$store] ?? 0.0),
                'buyback_rm' => (float) ($report['buyback_amount_by_store'][$store] ?? 0.0),
                'open_requests' => $requests[$store]['open'] ?? 0,
                'stale_requests' => $requests[$store]['stale'] ?? 0,
                'transit_pieces' => $transit[$store]['pieces'] ?? 0,
                'transit_grams' => round($transit[$store]['weight'] ?? 0.0, 2),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['rm'], $a['store']] <=> [$a['rm'], $b['store']]);

        $sum = fn (string $key) => array_sum(array_column($rows, $key));
        $cost = $sum('cost');
        $rm = $sum('rm');

        return [
            'available' => $report !== null,
            'date_label' => $date->format('d/m'),
            'scope_label' => StoreScope::label($stores),
            'totals' => [
                'rm' => round($rm, 2),
                'pcs' => (int) $sum('pcs'),
                'grams' => round($sum('grams'), 2),
                'gp_pct' => $rm > 0 ? round(($rm - $cost) / $rm * 100, 1) : null,
                'buyback_grams' => round($sum('buyback_grams'), 2),
                'buyback_rm' => round($sum('buyback_rm'), 2),
                'open_requests' => (int) $sum('open_requests'),
                'stale_requests' => (int) $sum('stale_requests'),
                'transit_pieces' => (int) $sum('transit_pieces'),
                'transit_grams' => round($sum('transit_grams'), 2),
            ],
            'rows' => array_map(fn (array $row) => array_diff_key($row, ['cost' => 0]), $rows),
        ];
    }

    /**
     * Permintaan stok aktif setiap cawangan: terbuka (<= STALE_REQUEST_DAYS hari) dan lapuk.
     *
     * @param  array<int, string>  $scope
     * @return array<string, array{open: int, stale: int}>
     */
    private function requests(array $scope): array
    {
        $cutoff = Carbon::now()->subDays(BranchSection::STALE_REQUEST_DAYS);
        $result = [];

        BranchDemandRequest::query()
            ->whereIn('status', [BranchDemandRequest::STATUS_SUBMITTED, BranchDemandRequest::STATUS_REVIEWED, BranchDemandRequest::STATUS_PROCESSING])
            ->get(['store_code', 'submitted_at', 'created_at'])
            ->each(function (BranchDemandRequest $request) use ($scope, $cutoff, &$result) {
                $store = strtoupper(trim((string) $request->store_code));

                if (! in_array($store, $scope, true)) {
                    return;
                }

                $key = ($request->submitted_at ?? $request->created_at)->lt($cutoff) ? 'stale' : 'open';
                $result[$store][$key] = ($result[$store][$key] ?? 0) + 1;
            });

        return $result;
    }

    /**
     * Keping Transit menuju setiap cawangan (cermin).
     *
     * @param  array<int, string>  $scope
     * @return array<string, array{pieces: int, weight: float}>
     */
    private function transit(array $scope): array
    {
        if ($scope === []) {
            return [];
        }

        return InventoryMirror::query()
            ->where('Status', JemisysInventoryStatus::Transit->value)
            ->whereIn(DB::raw('UPPER(TRIM(TransferToStore))'), $scope)
            ->selectRaw('UPPER(TRIM(TransferToStore)) AS to_store, COUNT(*) AS pieces, COALESCE(SUM(GoldWeight), 0) AS weight')
            ->groupByRaw('UPPER(TRIM(TransferToStore))')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->to_store => ['pieces' => (int) $r->pieces, 'weight' => (float) $r->weight]])
            ->all();
    }
}
