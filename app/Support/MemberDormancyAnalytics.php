<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ahli "dormant" (lama tak membeli) JEMiSys - AGREGAT SAHAJA, live ke 'jemisys', baca sahaja.
 *
 * Dormant diukur drpd tarikh pembelian TERAKHIR (jualan sah SI, Status '0') setiap ahli dlm tetingkap
 * WINDOW_DAYS (366 - had julat tool, rujuk SalesAnalytics::MAX_RANGE_DAYS). Ahli berdaftar yg tiada
 * pembelian dlm tetingkap = "tiada pembelian dlm tetingkap" (tak boleh bezakan tak pernah membeli
 * dgn >366 hari lalu - had tetingkap).
 *
 * PRIVASI: VIPNo TIDAK pernah dipilih (cuma dipakai dlm GROUP BY di SQL Server) - hanya agregat per ahli
 * (tarikh terakhir, belanja, cawangan/jenis pendaftaran) keluar drpd pangkalan data; tiada butiran
 * peribadi dibaca. Keselamatan query: tapisan tarikh wajib, WITH (NOLOCK), GROUP BY, had baris & masa.
 */
class MemberDormancyAnalytics
{
    public const WINDOW_DAYS = 366;

    public const MAX_ROWS = 150000;

    public const TIMEOUT_SECONDS = 60;

    /** Ahli dgn belanja dlm tetingkap >= ini + dormant = sasaran retensi bernilai tinggi (selaras tier hadiah RM7,000). */
    public const HIGH_VALUE_MIN_RM = 7000;

    /** @var array<int, array{label: string, min: int, max: int}> */
    public const BUCKETS = [
        ['label' => 'Aktif (0-30 hari)', 'min' => 0, 'max' => 30],
        ['label' => 'Sejuk (31-90 hari)', 'min' => 31, 'max' => 90],
        ['label' => 'Dormant (91-180 hari)', 'min' => 91, 'max' => 180],
        ['label' => 'Lapsed (181-366 hari)', 'min' => 181, 'max' => 366],
    ];

    private const CONNECTION = 'jemisys';

    /**
     * @return array<string, mixed>
     */
    public function summary(string $asOf, int $inactiveDays): array
    {
        $to = Carbon::parse($asOf)->toDateString();
        $from = Carbon::parse($to)->subDays(self::WINDOW_DAYS)->toDateString();
        SalesAnalytics::assertDateRange($from, $to);

        $toExclusive = Carbon::parse($to)->addDay()->toDateString();

        [$purchasers, $registered] = SqlServerQueryTimeout::run(self::CONNECTION, function () use ($from, $toExclusive) {
            $db = DB::connection(self::CONNECTION);

            // Satu baris per ahli (GROUP BY VIPNo) tetapi VIPNo TIDAK dipilih - hanya agregat.
            $purchasers = $db->table(DB::raw('TblSalesInvoice AS H WITH (NOLOCK)'))
                ->leftJoin(DB::raw('Member AS M WITH (NOLOCK)'), 'M.VIPNo', '=', 'H.VIPNo')
                ->where('H.Status', '0')
                ->where('H.DocType', 'SI')
                ->where('H.InvoiceDate', '>=', $from)
                ->where('H.InvoiceDate', '<', $toExclusive)
                ->whereNotNull('H.VIPNo')
                ->whereRaw("LTRIM(RTRIM(H.VIPNo)) <> ''")
                ->selectRaw('CONVERT(char(10), MAX(H.InvoiceDate), 23) AS last_purchase')
                ->selectRaw('SUM(H.NetAmount) AS spend')
                ->selectRaw('COUNT(*) AS invoices')
                ->selectRaw("MAX(ISNULL(UPPER(RTRIM(M.StoreCode)), '')) AS store_code")
                ->selectRaw("MAX(ISNULL(RTRIM(M.MemberType), '')) AS member_type")
                ->groupByRaw('RTRIM(H.VIPNo)')
                ->limit(self::MAX_ROWS)
                ->get();

            $registered = $db->table(DB::raw('Member AS M WITH (NOLOCK)'))
                ->whereNull('M.CancelledDate')
                ->where('M.MemberSince', '<', $toExclusive)
                ->selectRaw("ISNULL(UPPER(RTRIM(M.StoreCode)), '') AS store_code, ISNULL(RTRIM(M.MemberType), '') AS member_type, COUNT(*) AS members")
                ->groupByRaw("ISNULL(UPPER(RTRIM(M.StoreCode)), ''), ISNULL(RTRIM(M.MemberType), '')")
                ->limit(1000)
                ->get();

            return [$purchasers, $registered];
        }, self::TIMEOUT_SECONDS);

        $result = self::fromRows(
            $purchasers->map(fn ($r) => [
                'last_purchase' => (string) $r->last_purchase,
                'spend' => (float) $r->spend,
                'invoices' => (int) $r->invoices,
                'store_code' => (string) $r->store_code,
                'member_type' => (string) $r->member_type,
            ])->all(),
            $registered->map(fn ($r) => [
                'store_code' => (string) $r->store_code,
                'member_type' => (string) $r->member_type,
                'members' => (int) $r->members,
            ])->all(),
            $to,
            $inactiveDays,
        );

        $result['window_from'] = $from;
        $result['truncated'] = $purchasers->count() >= self::MAX_ROWS;

        return $result;
    }

    /**
     * Logik tanpa database (boleh diuji terus).
     *
     * @param  array<int, array{last_purchase: string, spend: float, invoices: int, store_code: string, member_type: string}>  $purchasers  satu baris = satu ahli yg membeli dlm tetingkap
     * @param  array<int, array{store_code: string, member_type: string, members: int}>  $registered  ahli berdaftar (tak dibatalkan) ikut cawangan & jenis pendaftaran
     * @return array<string, mixed>
     */
    public static function fromRows(array $purchasers, array $registered, string $asOf, int $inactiveDays): array
    {
        $registeredTotal = (int) array_sum(array_column($registered, 'members'));
        $purchaserCount = count($purchasers);
        $asOfTs = strtotime($asOf);

        $buckets = [];
        foreach (self::BUCKETS as $b) {
            $buckets[] = $b + ['members' => 0, 'spend' => 0.0];
        }

        $dormant = 0;
        $dormantSpend = 0.0;
        $highValue = 0;
        $highValueSpend = 0.0;

        /** @var array<string, array{registered: int, purchasers: int, active_30d: int, dormant: int}> $byStore */
        $byStore = [];
        $byType = [];

        foreach ($registered as $row) {
            $store = trim($row['store_code']) ?: '(tiada cawangan)';
            $type = trim($row['member_type']) ?: '(tiada jenis)';
            $byStore[$store] ??= ['registered' => 0, 'purchasers' => 0, 'active_30d' => 0, 'dormant' => 0];
            $byStore[$store]['registered'] += $row['members'];
            $byType[$type] ??= ['registered' => 0, 'purchasers' => 0, 'dormant' => 0];
            $byType[$type]['registered'] += $row['members'];
        }

        foreach ($purchasers as $p) {
            $days = max(0, (int) floor(($asOfTs - strtotime($p['last_purchase'])) / 86400));
            $store = trim($p['store_code']) ?: '(tiada cawangan)';
            $type = trim($p['member_type']) ?: '(tiada jenis)';

            $byStore[$store] ??= ['registered' => 0, 'purchasers' => 0, 'active_30d' => 0, 'dormant' => 0];
            $byType[$type] ??= ['registered' => 0, 'purchasers' => 0, 'dormant' => 0];
            $byStore[$store]['purchasers']++;
            $byType[$type]['purchasers']++;

            foreach ($buckets as $i => $b) {
                if ($days >= $b['min'] && $days <= $b['max']) {
                    $buckets[$i]['members']++;
                    $buckets[$i]['spend'] += $p['spend'];
                    break;
                }
            }

            if ($days <= 30) {
                $byStore[$store]['active_30d']++;
            }

            if ($days > $inactiveDays) {
                $dormant++;
                $dormantSpend += $p['spend'];
                $byStore[$store]['dormant']++;
                $byType[$type]['dormant']++;

                if ($p['spend'] >= self::HIGH_VALUE_MIN_RM) {
                    $highValue++;
                    $highValueSpend += $p['spend'];
                }
            }
        }

        $noPurchase = max(0, $registeredTotal - $purchaserCount);

        $pct = fn (int $n) => $registeredTotal > 0 ? round($n / $registeredTotal * 100, 1) : null;

        ksort($byStore);
        ksort($byType);

        return [
            'as_of' => $asOf,
            'inactive_days' => $inactiveDays,
            'registered_members' => $registeredTotal,
            'purchasers_in_window' => $purchaserCount,
            'no_purchase_in_window' => $noPurchase,
            'no_purchase_in_window_pct' => $pct($noPurchase),
            'dormant_members' => $dormant,
            'dormant_window_spend_rm' => round($dormantSpend, 2),
            'high_value_dormant' => [
                'min_window_spend_rm' => self::HIGH_VALUE_MIN_RM,
                'members' => $highValue,
                'window_spend_rm' => round($highValueSpend, 2),
            ],
            'buckets' => array_map(fn (array $b) => [
                'label' => $b['label'],
                'days_min' => $b['min'],
                'days_max' => $b['max'],
                'members' => $b['members'],
                'share_of_registered_pct' => $pct($b['members']),
                'window_spend_rm' => round($b['spend'], 2),
                'avg_spend_rm' => $b['members'] > 0 ? round($b['spend'] / $b['members'], 2) : null,
            ], $buckets),
            'by_registration_store' => collect($byStore)->map(fn (array $s, string $store) => [
                'store_code' => $store,
                'registered' => $s['registered'],
                'purchasers_in_window' => $s['purchasers'],
                'active_30d' => $s['active_30d'],
                'dormant' => $s['dormant'],
                'no_purchase_in_window' => max(0, $s['registered'] - $s['purchasers']),
            ])->values()->all(),
            'by_member_type' => collect($byType)->map(fn (array $t, string $type) => [
                'member_type' => $type,
                'registered' => $t['registered'],
                'purchasers_in_window' => $t['purchasers'],
                'dormant' => $t['dormant'],
                'no_purchase_in_window' => max(0, $t['registered'] - $t['purchasers']),
            ])->sortByDesc('registered')->values()->all(),
        ];
    }
}
