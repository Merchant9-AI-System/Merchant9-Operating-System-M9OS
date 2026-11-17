<?php

namespace App\Support\HomeSummary;

use App\Enums\JemisysInventoryStatus;
use App\Models\BranchDemandRequest;
use App\Models\InventoryMirror;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ringkasan semalam utk ketua cawangan (role leader_branch): jualan & buyback CAWANGAN SENDIRI sahaja
 * (ikut users.store_code - nilainya sering berpad ruang, mis. "DAMAI ", jadi sentiasa di-trim), status
 * permintaan stok cawangan & transfer yang menuju ke cawangan ini. Data cawangan lain TIDAK pernah dimuatkan
 * ke dalam hasil seksyen ni.
 */
class BranchSection implements SummarySection
{
    public const ROLE = 'leader_branch';

    /** Permintaan stok terbiar lebih lama drpd ini dipisahkan sebagai "lapuk". */
    public const STALE_REQUEST_DAYS = 14;

    public function __construct(private YesterdaySnapshot $snapshot) {}

    public function key(): string
    {
        return 'branch';
    }

    public function title(): string
    {
        return 'Cawangan Anda';
    }

    public function appliesTo(User $user): bool
    {
        return $user->hasRole(self::ROLE) && self::storeOf($user) !== '';
    }

    public function build(User $user, Carbon $date): array
    {
        $store = self::storeOf($user);
        $report = $this->snapshot->report($date);

        $items = $report === null
            ? []
            : self::salesItems($report, $store, $date->format('d/m'));

        $result = ['items' => [...$items, ...self::workflowItems($store)]];

        if ($report === null) {
            $result['note'] = 'Data jualan semalam tidak dapat diambil drpd JEMiSys sekarang - cuba lagi sebentar.';
        }

        return $result;
    }

    public static function storeOf(User $user): string
    {
        return strtoupper(trim((string) $user->store_code));
    }

    /**
     * @param  array<string, mixed>  $report  DailyHedgeFigure::forDate()
     * @return array<int, array<string, string|null>>
     */
    public static function salesItems(array $report, string $store, ?string $dayLabel = null): array
    {
        // Cip pada kad: tandakan jelas angka ini data SEMALAM (beza drpd kad "Semasa" di bawahnya).
        $tag = $dayLabel !== null ? 'Semalam · '.$dayLabel : 'Semalam';
        $totals = ['qty' => 0.0, 'gold_weight' => 0.0, 'total_price' => 0.0, 'cost' => 0.0];

        foreach ($report['stores'][$store] ?? [] as $line) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (float) $line[$key];
            }
        }

        $gpPct = $totals['total_price'] > 0 ? ($totals['total_price'] - $totals['cost']) / $totals['total_price'] * 100 : null;
        $buyback = (float) ($report['buyback_by_store'][$store] ?? 0.0);
        $buybackRm = $report['buyback_amount_by_store'][$store] ?? null;
        $hadSales = $totals['qty'] > 0;

        return [
            // Semua kad sama saiz (tiada hero): nilai jualan (RM) sebagai angka utama; pcs, gram & GP di bawahnya.
            [
                'key' => 'sales',
                'label' => 'Jualan semalam',
                'tag' => $tag,
                'value' => 'RM'.number_format($totals['total_price'], 2),
                'hint' => $hadSales
                    ? number_format($totals['qty']).' pcs · '.number_format($totals['gold_weight'], 2).' g'.($gpPct !== null ? ' · GP '.number_format($gpPct, 1).'%' : '')
                    : 'Tiada jualan semalam',
                'tone' => $hadSales ? 'default' : 'warn',
            ],
            [
                'key' => 'buyback',
                'label' => 'Buyback / trade-in',
                'tag' => $tag,
                'value' => number_format($buyback, 2).' g',
                'hint' => ($buybackRm !== null ? 'RM'.number_format((float) $buybackRm, 2).' · ' : '').'TI + TC',
                'tone' => 'default',
            ],
        ];
    }

    /** @return array<int, array<string, string|null>> */
    private static function workflowItems(string $store): array
    {
        // Permintaan yg terbiar > STALE_REQUEST_DAYS hari tanpa tindakan dianggap lapuk (kemungkinan sudah
        // diselesaikan di luar sistem) - dipisahkan drpd bilangan "terbuka".
        $cutoff = now()->subDays(self::STALE_REQUEST_DAYS);

        $requests = BranchDemandRequest::query()
            ->whereRaw('UPPER(TRIM(store_code)) = ?', [$store])
            ->whereIn('status', [BranchDemandRequest::STATUS_SUBMITTED, BranchDemandRequest::STATUS_REVIEWED, BranchDemandRequest::STATUS_PROCESSING])
            ->get(['status', 'submitted_at', 'created_at']);

        $isStale = fn (BranchDemandRequest $r): bool => ($r->submitted_at ?? $r->created_at)->lt($cutoff);
        $fresh = $requests->reject($isStale);
        $stale = $requests->filter($isStale);
        $openTotal = $fresh->count();

        $parts = [];

        if ($openTotal > 0) {
            $parts[] = $fresh->where('status', BranchDemandRequest::STATUS_SUBMITTED)->count().' menunggu semakan, '
                .$fresh->where('status', BranchDemandRequest::STATUS_PROCESSING)->count().' sedang diproses';
        }

        if ($stale->isNotEmpty()) {
            $oldest = (int) $stale->map(fn (BranchDemandRequest $r) => ($r->submitted_at ?? $r->created_at)->diffInDays(now()))->max();
            $parts[] = $stale->count().' lapuk (>'.self::STALE_REQUEST_DAYS.' hari, tertua '.$oldest.' hari)';
        }

        $incoming = self::incomingTransit($store);

        return [
            [
                'key' => 'open_requests',
                'label' => 'Permintaan stok terbuka',
                'tag' => 'Semasa',
                // Halaman Permintaan Stok Cawangan: memuatkan permintaan terkini cawangan ini utk disemak/ditambah.
                'link' => ['label' => 'Lihat permintaan terkini', 'href' => route('branch-demand.create')],
                'value' => (string) $openTotal,
                'hint' => $parts === [] ? 'Tiada permintaan terbuka' : implode(' · ', $parts),
                'tone' => 'default',
            ],
            [
                'key' => 'incoming_transit',
                'label' => 'Barang dalam transit ke cawangan ini',
                'tag' => 'Semasa',
                'value' => number_format($incoming['pieces']).' keping',
                'hint' => self::incomingHint($incoming),
                'tone' => $incoming['pieces'] > 0 ? 'warn' : 'default',
            ],
        ];
    }

    /**
     * Barang yang SEDANG DALAM TRANSIT menuju cawangan ini, diambil drpd cermin JEMiSys (jemisys_inventory_mirror):
     * Status "Transit" + TransferToStore = cawangan ini. Sengaja BUKAN drpd jadual stock_transfers m9os - staf
     * terima/hantar barang dlm JEMiSys sebenar, status dlm m9os tak dikemas kini. Cermin = salinan sandaran
     * JEMiSys, jadi masa segerak terakhir dipaparkan.
     *
     * Modul stock transfer m9os tak pernah ditanda Received, jadi bilangan transit ke cawangan ini disemak silang
     * dgn cermin JEMiSys ini (bukan jadual stock_transfers).
     *
     * @return array{pieces: int, weight: float, from: array<string, int>, synced_at: Carbon|null}
     */
    public static function incomingTransit(string $store): array
    {
        return self::mirrorIncomingTransit($store);
    }

    /** @return array{pieces: int, weight: float, from: array<string, int>, synced_at: Carbon|null} */
    private static function mirrorIncomingTransit(string $store): array
    {
        $rows = InventoryMirror::query()
            ->where('Status', JemisysInventoryStatus::Transit->value)
            ->whereRaw('UPPER(TRIM(TransferToStore)) = ?', [$store])
            ->selectRaw('UPPER(TRIM(StoreCode)) AS from_store, COUNT(*) AS pieces, COALESCE(SUM(GoldWeight), 0) AS weight, MAX(synced_at) AS synced_at')
            ->groupByRaw('UPPER(TRIM(StoreCode))')
            ->get();

        $syncedAt = $rows->max('synced_at');

        return [
            'pieces' => (int) $rows->sum('pieces'),
            'weight' => (float) $rows->sum('weight'),
            'from' => $rows->sortByDesc('pieces')->mapWithKeys(fn ($r) => [(string) $r->from_store => (int) $r->pieces])->all(),
            'synced_at' => $syncedAt ? Carbon::parse($syncedAt) : null,
        ];
    }

    /** @param  array{pieces: int, weight: float, from: array<string, int>, synced_at: Carbon|null}  $incoming */
    private static function incomingHint(array $incoming): string
    {
        if ($incoming['pieces'] === 0) {
            return 'Tiada barang dalam transit';
        }

        $from = collect($incoming['from'])->map(fn (int $n, string $store) => "{$store} {$n}")->implode(', ');
        $hint = number_format($incoming['weight'], 2).' g · dari '.$from;

        return $incoming['synced_at']
            ? $hint.' · data JEMiSys '.$incoming['synced_at']->format('d/m H:i')
            : $hint;
    }
}
