<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aktiviti ahli (customer berahli) JEMiSys - AGREGAT SAHAJA, dibaca live ke 'jemisys'.
 *
 * Sumber (disahkan 8 Okt 2026): TblSalesInvoice.VIPNo -> Member (PK VIPNo; SEMUA 1,372 invois 1-8 Okt ada
 * rekod Member, tiada akaun walk-in generik), MemTransactions (mata ganjaran ikut invois).
 *
 * PRIVASI: jadual Member ada ~98 lajur termasuk NRIC, alamat, telefon, e-mel & kata laluan WeChat -
 * HANYA lajur senarai putih dibaca (VIPNo sbg kunci dalaman, MemberType, MemberSince, StoreCode) & nombor
 * ahli TIDAK dipulangkan. Jualan sah sahaja (Status '0', DocType 'SI'), sama seperti GiftTierAnalytics.
 * Keselamatan query (rujuk AI_AGENTS_PLAN.md): tapisan tarikh wajib, WITH (NOLOCK), semua query GROUP BY,
 * had baris & masa.
 */
class MemberAnalytics
{
    public const MAX_ROWS = 100000;

    public const TIMEOUT_SECONDS = 60;

    /** Jalur belanja ahli dlm tempoh (RM) - minimum setiap jalur. */
    public const SPEND_BANDS = [0, 500, 1000, 3000, 7000, 13000];

    private const CONNECTION = 'jemisys';

    /**
     * @return array<string, mixed>
     */
    public function summary(string $dateFrom, string $dateTo, ?string $storeCode): array
    {
        SalesAnalytics::assertDateRange($dateFrom, $dateTo);

        $from = Carbon::parse($dateFrom)->toDateString();
        $toExclusive = Carbon::parse($dateTo)->addDay()->toDateString();
        $store = $storeCode ? strtoupper(trim($storeCode)) : null;

        [$buyers, $newMembers, $points] = SqlServerQueryTimeout::run(self::CONNECTION, function () use ($from, $toExclusive, $store) {
            $db = DB::connection(self::CONNECTION);

            $buyers = $db->table(DB::raw('TblSalesInvoice AS H WITH (NOLOCK)'))
                ->leftJoin(DB::raw('Member AS M WITH (NOLOCK)'), 'M.VIPNo', '=', 'H.VIPNo')
                ->where('H.Status', '0')
                ->where('H.DocType', 'SI')
                ->where('H.InvoiceDate', '>=', $from)
                ->where('H.InvoiceDate', '<', $toExclusive)
                ->whereNotNull('H.VIPNo')
                ->whereRaw("LTRIM(RTRIM(H.VIPNo)) <> ''")
                ->when($store, fn ($q, $code) => $q->whereRaw('UPPER(RTRIM(H.StoreCode)) = ?', [$code]))
                ->selectRaw('RTRIM(H.VIPNo) AS vip_no')
                ->selectRaw('UPPER(RTRIM(H.StoreCode)) AS store_code')
                ->selectRaw('SUM(H.NetAmount) AS spend')
                ->selectRaw('COUNT(*) AS invoices')
                ->selectRaw("MAX(ISNULL(RTRIM(M.MemberType), '')) AS member_type")
                ->selectRaw('MAX(CASE WHEN M.MemberSince >= ? AND M.MemberSince < ? THEN 1 ELSE 0 END) AS is_new', [$from, $toExclusive])
                ->groupByRaw('RTRIM(H.VIPNo), UPPER(RTRIM(H.StoreCode))')
                ->limit(self::MAX_ROWS)
                ->get();

            $newMembers = $db->table(DB::raw('Member AS M WITH (NOLOCK)'))
                ->where('M.MemberSince', '>=', $from)
                ->where('M.MemberSince', '<', $toExclusive)
                ->when($store, fn ($q, $code) => $q->whereRaw('UPPER(RTRIM(M.StoreCode)) = ?', [$code]))
                ->selectRaw("UPPER(RTRIM(M.StoreCode)) AS store_code, ISNULL(RTRIM(M.MemberType), '') AS member_type, COUNT(*) AS members")
                ->groupByRaw("UPPER(RTRIM(M.StoreCode)), ISNULL(RTRIM(M.MemberType), '')")
                ->limit(1000)
                ->get();

            $points = $db->table(DB::raw('MemTransactions AS T WITH (NOLOCK)'))
                ->where('T.InvoiceDate', '>=', $from)
                ->where('T.InvoiceDate', '<', $toExclusive)
                ->when($store, fn ($q, $code) => $q->whereRaw('UPPER(RTRIM(T.StoreCode)) = ?', [$code]))
                ->selectRaw('UPPER(RTRIM(T.StoreCode)) AS store_code')
                ->selectRaw('SUM(T.TotalPointsGained) AS gained')
                ->selectRaw('SUM(T.TotalPointsUsed) AS used')
                ->selectRaw('SUM(T.TotalPointsRedeemed) AS redeemed')
                ->groupByRaw('UPPER(RTRIM(T.StoreCode))')
                ->limit(100)
                ->get();

            return [$buyers, $newMembers, $points];
        }, self::TIMEOUT_SECONDS);

        $result = self::fromRows(
            $buyers->map(fn ($r) => [
                'vip_no' => (string) $r->vip_no,
                'store_code' => (string) $r->store_code,
                'spend' => (float) $r->spend,
                'invoices' => (int) $r->invoices,
                'member_type' => (string) $r->member_type,
                'is_new' => (bool) $r->is_new,
            ])->all(),
            $newMembers->map(fn ($r) => [
                'store_code' => (string) $r->store_code,
                'member_type' => (string) $r->member_type,
                'members' => (int) $r->members,
            ])->all(),
            $points->map(fn ($r) => [
                'store_code' => (string) $r->store_code,
                'gained' => (int) $r->gained,
                'used' => (int) $r->used,
                'redeemed' => (int) $r->redeemed,
            ])->all(),
        );

        $result['truncated'] = $buyers->count() >= self::MAX_ROWS;

        return $result;
    }

    /**
     * Logik agregat tanpa database (boleh diuji terus).
     *
     * @param  array<int, array{vip_no: string, store_code: string, spend: float, invoices: int, member_type: string, is_new: bool}>  $buyers  satu baris = satu ahli di satu cawangan
     * @param  array<int, array{store_code: string, member_type: string, members: int}>  $newMembers  ahli didaftar dlm tempoh
     * @param  array<int, array{store_code: string, gained: int, used: int, redeemed: int}>  $points
     * @return array<string, mixed>
     */
    public static function fromRows(array $buyers, array $newMembers, array $points): array
    {
        /** @var array<string, array{spend: float, invoices: int, type: string, is_new: bool, stores: array<string, true>}> $perMember */
        $perMember = [];
        /** @var array<string, array<string, true>> $storeMembers store => [vip => true] */
        $storeMembers = [];

        foreach ($buyers as $row) {
            $vip = trim($row['vip_no']);
            $store = strtoupper(trim($row['store_code']));

            $perMember[$vip] ??= ['spend' => 0.0, 'invoices' => 0, 'type' => '', 'is_new' => false, 'stores' => []];
            $perMember[$vip]['spend'] += $row['spend'];
            $perMember[$vip]['invoices'] += $row['invoices'];
            $perMember[$vip]['type'] = $perMember[$vip]['type'] !== '' ? $perMember[$vip]['type'] : trim($row['member_type']);
            $perMember[$vip]['is_new'] = $perMember[$vip]['is_new'] || $row['is_new'];
            $perMember[$vip]['stores'][$store] = true;
            $storeMembers[$store][$vip] = true;
        }

        $active = count($perMember);
        $repeat = 0;
        $activeNew = 0;
        $bands = [];
        $types = [];

        foreach (self::SPEND_BANDS as $i => $min) {
            $next = self::SPEND_BANDS[$i + 1] ?? null;
            $bands[$i] = [
                'band' => $next === null ? number_format($min).' ke atas' : ($min === 0 ? '< '.number_format($next) : number_format($min).' - '.number_format($next - 1)),
                'min_rm' => $min,
                'members' => 0,
                'spend_rm' => 0.0,
            ];
        }

        foreach ($perMember as $m) {
            $repeat += $m['invoices'] >= 2 ? 1 : 0;
            $activeNew += $m['is_new'] ? 1 : 0;

            $band = 0;
            foreach (self::SPEND_BANDS as $i => $min) {
                if ($m['spend'] >= $min) {
                    $band = $i;
                }
            }
            $bands[$band]['members']++;
            $bands[$band]['spend_rm'] += $m['spend'];

            $type = $m['type'] !== '' ? $m['type'] : '(tiada jenis)';
            $types[$type] ??= ['member_type' => $type, 'active_members' => 0, 'spend_rm' => 0.0];
            $types[$type]['active_members']++;
            $types[$type]['spend_rm'] += $m['spend'];
        }

        $newByStore = [];
        $newByType = [];
        $newTotal = 0;

        foreach ($newMembers as $row) {
            $store = strtoupper(trim($row['store_code'])) ?: '(tiada cawangan)';
            $type = trim($row['member_type']) ?: '(tiada jenis)';
            $newByStore[$store] = ($newByStore[$store] ?? 0) + $row['members'];
            $newByType[$type] = ($newByType[$type] ?? 0) + $row['members'];
            $newTotal += $row['members'];
        }

        $stores = array_unique(array_merge(array_keys($storeMembers), array_keys($newByStore)));
        sort($stores);

        $byStore = array_map(fn (string $store) => [
            'store_code' => $store,
            'active_members' => count($storeMembers[$store] ?? []),
            'new_members' => $newByStore[$store] ?? 0,
        ], $stores);

        usort($byStore, fn (array $a, array $b) => [$b['active_members'], $a['store_code']] <=> [$a['active_members'], $b['store_code']]);

        $gained = array_sum(array_column($points, 'gained'));
        $used = array_sum(array_column($points, 'used'));
        $redeemed = array_sum(array_column($points, 'redeemed'));

        $totalSpend = array_sum(array_column($perMember, 'spend'));

        return [
            'active_members' => $active,
            'new_members_registered' => $newTotal,
            'active_members_registered_in_period' => $activeNew,
            'repeat_members' => $repeat,
            'repeat_rate_pct' => $active > 0 ? round($repeat / $active * 100, 1) : null,
            'avg_spend_per_active_member_rm' => $active > 0 ? round($totalSpend / $active, 2) : null,
            'spend_bands' => array_map(fn (array $b) => [
                'band' => $b['band'],
                'min_rm' => $b['min_rm'],
                'members' => $b['members'],
                'share_pct' => $active > 0 ? round($b['members'] / $active * 100, 1) : null,
                'spend_rm' => round($b['spend_rm'], 2),
            ], $bands),
            'by_member_type' => collect($types)
                ->map(fn (array $t) => ['member_type' => $t['member_type'], 'active_members' => $t['active_members'], 'spend_rm' => round($t['spend_rm'], 2)])
                ->sortByDesc('active_members')->values()->all(),
            'new_members_by_type' => collect($newByType)->map(fn (int $n, string $type) => ['member_type' => $type, 'members' => $n])->sortByDesc('members')->values()->all(),
            'by_store' => $byStore,
            'points' => [
                'gained' => $gained,
                'used' => $used,
                'redeemed' => $redeemed,
                'used_pct_of_gained' => $gained > 0 ? round($used / $gained * 100, 1) : null,
            ],
        ];
    }
}
