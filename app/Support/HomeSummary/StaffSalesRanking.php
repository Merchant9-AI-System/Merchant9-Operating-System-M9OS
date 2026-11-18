<?php

namespace App\Support\HomeSummary;

/**
 * Ranking jualan staf satu cawangan (M01 Ticket + M06 Staff Ranking digabung) - pengiraan tulen tanpa
 * pangkalan data, jadi mudah diuji.
 *
 * Peraturan (diputuskan pengguna): jualan yg ada SalesMan2 berbeza dikongsi SAMA RATA - setiap orang dapat 50% RM,
 * gram dan tiket (jadi tiket boleh berpecahan, cth. 12.5; jumlah semua staf tetap = bilangan invois). SalesMan2 sama
 * dgn SalesMan atau kosong = kredit penuh kpd SalesMan. Staf dikenal pasti ikut (cawangan, nama) - nama yg sama di
 * cawangan berlainan (cth. staf bertukar-tukar cawangan) ialah ranking berasingan. "Tiket" = invois jualan;
 * purata tiket = RM / tiket. KPI HQ dan kehadiran belum ada sumbernya, jadi ranking v1 hanya ikut jualan.
 */
class StaffSalesRanking
{
    public const UNNAMED = '(TIADA NAMA)';

    /**
     * @param  array<int, array{store: string, sm1: string, sm2: string, tickets: int|float, rm: float, grams: float}>  $rows  drpd StaffSales
     * @return array{total: array{rm: float, grams: float, tickets: float, avg_ticket: float|null, staff: int}, rows: array<int, array<string, float|int|string|bool|null>>}
     */
    public static function build(array $rows, string $store): array
    {
        $store = strtoupper(trim($store));
        $staff = [];

        foreach ($rows as $row) {
            if (strtoupper(trim($row['store'])) !== $store) {
                continue;
            }

            $first = self::name($row['sm1']);
            $second = self::name($row['sm2'], null);
            $credits = $second !== null && $second !== $first
                ? [$first => 0.5, $second => 0.5]
                : [$first => 1.0];

            foreach ($credits as $name => $share) {
                $staff[$name] ??= ['rm' => 0.0, 'grams' => 0.0, 'tickets' => 0.0, 'shared' => false];
                $staff[$name]['rm'] += $row['rm'] * $share;
                $staff[$name]['grams'] += $row['grams'] * $share;
                $staff[$name]['tickets'] += $row['tickets'] * $share;
                $staff[$name]['shared'] = $staff[$name]['shared'] || $share < 1.0;
            }
        }

        $totalRm = array_sum(array_column($staff, 'rm'));
        $totalGrams = array_sum(array_column($staff, 'grams'));
        $totalTickets = array_sum(array_column($staff, 'tickets'));

        uasort($staff, fn (array $a, array $b) => [$b['rm'], $b['tickets']] <=> [$a['rm'], $a['tickets']]);

        $ranked = [];

        foreach ($staff as $name => $s) {
            $ranked[] = [
                'name' => (string) $name,
                'rm' => round($s['rm'], 2),
                'grams' => round($s['grams'], 2),
                'tickets' => round($s['tickets'], 1),
                'avg_ticket' => $s['tickets'] > 0 ? round($s['rm'] / $s['tickets'], 2) : null,
                'share_pct' => $totalRm > 0 ? round($s['rm'] / $totalRm * 100, 1) : null,
                'shared' => $s['shared'],
            ];
        }

        return [
            'total' => [
                'rm' => round($totalRm, 2),
                'grams' => round($totalGrams, 2),
                'tickets' => round($totalTickets, 1),
                'avg_ticket' => $totalTickets > 0 ? round($totalRm / $totalTickets, 2) : null,
                'staff' => count($ranked),
            ],
            'rows' => $ranked,
        ];
    }

    /**
     * Ranking staf merentas BANYAK cawangan (modul universal): peraturan kredit sama seperti build(), tetapi staf dikenali
     * ikut (cawangan, nama) dan setiap baris membawa `store`. `$stores` ialah senarai cawangan dlm skop (huruf besar).
     *
     * @param  array<int, array{store: string, sm1: string, sm2: string, tickets: int|float, rm: float, grams: float}>  $rows
     * @param  array<int, string>  $stores
     * @return array{total: array{rm: float, grams: float, tickets: float, avg_ticket: float|null, staff: int}, rows: array<int, array<string, float|int|string|bool|null>>}
     */
    public static function buildForStores(array $rows, array $stores): array
    {
        $stores = array_map(fn (string $s) => strtoupper(trim($s)), $stores);
        $staff = [];

        foreach ($rows as $row) {
            $store = strtoupper(trim($row['store']));

            if (! in_array($store, $stores, true)) {
                continue;
            }

            $first = self::name($row['sm1']);
            $second = self::name($row['sm2'], null);
            $credits = $second !== null && $second !== $first ? [$first => 0.5, $second => 0.5] : [$first => 1.0];

            foreach ($credits as $name => $share) {
                $key = $store.'|'.$name;
                $staff[$key] ??= ['store' => $store, 'name' => (string) $name, 'rm' => 0.0, 'grams' => 0.0, 'tickets' => 0.0, 'shared' => false];
                $staff[$key]['rm'] += $row['rm'] * $share;
                $staff[$key]['grams'] += $row['grams'] * $share;
                $staff[$key]['tickets'] += $row['tickets'] * $share;
                $staff[$key]['shared'] = $staff[$key]['shared'] || $share < 1.0;
            }
        }

        $totalRm = array_sum(array_column($staff, 'rm'));
        $totalGrams = array_sum(array_column($staff, 'grams'));
        $totalTickets = array_sum(array_column($staff, 'tickets'));

        uasort($staff, fn (array $a, array $b) => [$b['rm'], $b['tickets']] <=> [$a['rm'], $a['tickets']]);

        $ranked = array_map(fn (array $s) => [
            'name' => $s['name'],
            'store' => $s['store'],
            'rm' => round($s['rm'], 2),
            'grams' => round($s['grams'], 2),
            'tickets' => round($s['tickets'], 1),
            'avg_ticket' => $s['tickets'] > 0 ? round($s['rm'] / $s['tickets'], 2) : null,
            'share_pct' => $totalRm > 0 ? round($s['rm'] / $totalRm * 100, 1) : null,
            'shared' => $s['shared'],
        ], array_values($staff));

        return [
            'total' => [
                'rm' => round($totalRm, 2),
                'grams' => round($totalGrams, 2),
                'tickets' => round($totalTickets, 1),
                'avg_ticket' => $totalTickets > 0 ? round($totalRm / $totalTickets, 2) : null,
                'staff' => count($ranked),
            ],
            'rows' => $ranked,
        ];
    }

    private static function name(?string $value, ?string $fallback = self::UNNAMED): ?string
    {
        $name = mb_strtoupper(trim((string) $value));

        return $name === '' ? $fallback : $name;
    }
}
