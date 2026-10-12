<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan "Free Gift - Customer": berapa customer (VIPNo) layak setiap tier hadiah dalam satu tempoh,
 * dipecah ikut cawangan utama (cawangan dengan belian terbesar customer itu) + anggaran unit barang hadiah.
 * Live ke 'jemisys' (TblSalesInvoice), baca sahaja.
 *
 * Peraturan (sama seperti laporan Excel Free Gift Sept 2026, disahkan drpd data sebenar):
 * - Jualan sah sahaja: Status '0' (batal 'V' dikecualikan), DocType 'SI' (pemulangan tak ditolak).
 * - Hanya invois yg ada VIPNo (customer berahli).
 * - Tier ditentukan drpd JUMLAH NetAmount SEMUA invois customer dlm tempoh (config/gift_tiers.php).
 *
 * Privasi: VIPNo ialah data peribadi - TIDAK dipulangkan, hanya bilangan.
 * Keselamatan query (rujuk AI_AGENTS_PLAN.md): tapisan tarikh wajib (SalesAnalytics::assertDateRange),
 * WITH (NOLOCK), GROUP BY (agregat tanpa GROUP BY pernah >4 minit), lajur disenaraikan, had baris & masa.
 */
class GiftTierAnalytics
{
    /** Had baris (VIPNo x cawangan) - sebulan biasa ~4,000; setahun ~50,000. */
    public const MAX_ROWS = 100000;

    /** Lebih longgar drpd lalai 30s: cache SQL Server yg sejuk (selepas restore harian) pernah ambil ~9s utk satu bulan. */
    public const TIMEOUT_SECONDS = 60;

    private const CONNECTION = 'jemisys';

    /**
     * @return array<string, mixed>
     */
    public function summary(string $dateFrom, string $dateTo): array
    {
        SalesAnalytics::assertDateRange($dateFrom, $dateTo);

        $rows = SqlServerQueryTimeout::run(self::CONNECTION, fn () => DB::connection(self::CONNECTION)
            ->table(DB::raw('TblSalesInvoice AS H WITH (NOLOCK)'))
            ->where('H.Status', '0')
            ->where('H.DocType', 'SI')
            ->where('H.InvoiceDate', '>=', Carbon::parse($dateFrom)->toDateString())
            ->where('H.InvoiceDate', '<', Carbon::parse($dateTo)->addDay()->toDateString())
            ->whereNotNull('H.VIPNo')
            ->whereRaw("LTRIM(RTRIM(H.VIPNo)) <> ''")
            ->selectRaw('RTRIM(H.VIPNo) AS vip_no')
            ->selectRaw('UPPER(RTRIM(H.StoreCode)) AS store_code')
            ->selectRaw('SUM(H.NetAmount) AS branch_total')
            ->groupByRaw('RTRIM(H.VIPNo), UPPER(RTRIM(H.StoreCode))')
            ->limit(self::MAX_ROWS)
            ->get(), self::TIMEOUT_SECONDS);

        $result = self::fromRows($rows->map(fn ($r) => [
            'vip_no' => (string) $r->vip_no,
            'store_code' => (string) $r->store_code,
            'branch_total' => (float) $r->branch_total,
        ])->all());

        $result['truncated'] = $rows->count() >= self::MAX_ROWS;

        return $result;
    }

    /**
     * Logik tier tanpa database (boleh diuji terus).
     *
     * @param  array<int, array{vip_no: string, store_code: string, branch_total: float}>  $rows  satu baris = satu customer di satu cawangan
     * @return array{total_customers: int, multi_branch_customers: int, tiers: array<int, array<string, mixed>>, branches: array<int, string>, gift_units: array<int, array{item: string, units: int}>, choice_gifts: array<int, array{label: string, options: array<int, string>, customers: int}>}
     */
    public static function fromRows(array $rows): array
    {
        $tiers = config('gift_tiers.tiers');

        /** @var array<string, array<string, float>> $perCustomer vip => [store => jumlah] */
        $perCustomer = [];

        foreach ($rows as $row) {
            $vip = trim($row['vip_no']);
            $store = strtoupper(trim($row['store_code']));
            $perCustomer[$vip][$store] = ($perCustomer[$vip][$store] ?? 0) + $row['branch_total'];
        }

        $counts = array_fill(0, count($tiers), 0);
        $byBranch = array_fill(0, count($tiers), []);
        $branches = [];
        $multiBranch = 0;

        foreach ($perCustomer as $stores) {
            $total = array_sum($stores);

            // Cawangan utama = belian terbesar; seri -> nama cawangan (susunan tetap, hasil tak berubah-ubah).
            uksort($stores, fn (string $a, string $b) => [$stores[$b], $a] <=> [$stores[$a], $b]);
            $main = array_key_first($stores);

            $tierIndex = self::tierIndexFor($total, $tiers);
            $counts[$tierIndex]++;
            $byBranch[$tierIndex][$main] = ($byBranch[$tierIndex][$main] ?? 0) + 1;
            $branches[$main] = true;

            if (count($stores) > 1) {
                $multiBranch++;
            }
        }

        $branchList = array_keys($branches);
        sort($branchList);

        $tierRows = [];
        $units = [];
        $choice = [];

        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1]['min'] ?? null;
            ksort($byBranch[$i]);

            $tierRows[] = [
                'gift' => $tier['label'],
                'min_purchase_rm' => $tier['min'],
                'max_purchase_rm' => $next === null ? null : $next - 1,
                'customers' => $counts[$i],
                'by_branch' => $byBranch[$i],
            ];

            foreach ($tier['items'] as $item) {
                $units[$item] = ($units[$item] ?? 0) + $counts[$i];
            }

            if (! empty($tier['choice'])) {
                $choice[] = ['label' => $tier['label'], 'options' => $tier['choice'], 'customers' => $counts[$i]];
            }
        }

        return [
            'total_customers' => count($perCustomer),
            'multi_branch_customers' => $multiBranch,
            'tiers' => $tierRows,
            'branches' => $branchList,
            'gift_units' => collect($units)->map(fn (int $n, string $item) => ['item' => $item, 'units' => $n])->values()->all(),
            'choice_gifts' => $choice,
        ];
    }

    /**
     * Tier tertinggi yg jumlah belian layak (sempadan termasuk: RM1,000.00 tepat = tier RM1,000).
     *
     * @param  array<int, array<string, mixed>>  $tiers  disusun menaik ikut 'min'
     */
    public static function tierIndexFor(float $total, array $tiers): int
    {
        $index = 0;

        foreach ($tiers as $i => $tier) {
            if ($total >= $tier['min']) {
                $index = $i;
            }
        }

        return $index;
    }
}
