<?php

namespace App\Support;

use App\Models\BranchDemandRequest;
use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\InventoryPiece;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Angka widget dashboard "Aktiviti Cawangan" (App\Filament\Widgets\BranchActivityStats) bagi SATU cawangan (atau semua cawangan jualan
 * bila $store null):
 *
 *   Receive          = barang MASUK ke cawangan yg belum diterima + barang demand request cawangan itu yg sudah dihantar, bulan ini:
 *                      - transfer to_store = cawangan, status Requested / In Transit, requested_at bulan ini (jumlah qty);
 *                      - line demand request cawangan itu, fulfillment_status = dah_delivery, bulan ini (done_at, jika tiada updated_at),
 *                        TANPA line yg sudah ada transfer (barang yg sama sudah terkira di atas - elak kira dua kali).
 *   Branch Request   = transfer from_store = cawangan (cawangan LAIN minta daripada kita) yg BELUM selesai: Requested / In Transit
 *                      (jumlah qty, tanpa had bulan).
 *   Slow Moving      = design yg ada stok di cawangan, TIADA jualan di cawangan itu dlm SLOW_WINDOW_DAYS, DAN stok tertuanya sudah
 *                      > SLOW_MIN_AGE_DAYS (barang baru masuk tak dikira lembap).
 *   Fast Selling     = design yg terjual >= FAST_MIN_SOLD piece di cawangan itu dlm FAST_WINDOW_DAYS terakhir.
 *
 * Slow/Fast dikira drpd cermin JEMiSys (berat) - di-cache ikut cawangan, kunci mengandungi masa segerak cermin terakhir jadi sah
 * sehingga segerak berikutnya. Receive/Branch Request ialah jadual kecil - dikira SEGAR setiap kali (status BO/transfer terkini).
 * Padanan cawangan sentiasa trim + abaikan huruf besar/kecil (from_store/to_store boleh berpadding, rujuk StockTransfer::notifyBranches()).
 */
final class BranchActivityCalculator
{
    public const FAST_WINDOW_DAYS = 30;

    public const FAST_MIN_SOLD = 3;

    public const SLOW_WINDOW_DAYS = 180;

    public const SLOW_MIN_AGE_DAYS = 180;

    /** Transfer yg masih "dalam perjalanan" (belum diterima, belum dibatalkan). @var array<int, string> */
    public const PENDING_TRANSFER_STATUSES = [StockTransfer::STATUS_REQUESTED, StockTransfer::STATUS_IN_TRANSIT];

    private const CACHE_MINUTES = 1440;

    /**
     * Cawangan jualan (tanpa HQ, WEB, Security) - sama senarai dgn Back Office Actions.
     *
     * @return list<string>
     */
    public static function branches(): array
    {
        return array_values(app(BackOfficeActionsAdvisor::class)->branchCodes());
    }

    /**
     * @return array{
     *     receive: array{qty: int, transfers: int, transfer_qty: int, demand_qty: int},
     *     branch_request: array{qty: int, transfers: int},
     *     slow: array{designs: int, pieces: int},
     *     fast: array{designs: int, pieces: int}
     * }
     */
    public static function forStore(?string $store): array
    {
        $store = $store === null || trim($store) === '' ? null : trim($store);

        return [
            ...self::movements($store),
            ...self::velocity($store),
        ];
    }

    /**
     * @return array{receive: array{qty: int, transfers: int, transfer_qty: int, demand_qty: int}, branch_request: array{qty: int, transfers: int}}
     */
    private static function movements(?string $store): array
    {
        $monthStart = now()->startOfMonth();

        $incoming = StockTransfer::query()
            ->whereIn('status', self::PENDING_TRANSFER_STATUSES)
            ->where('requested_at', '>=', $monthStart);
        self::matchStore($incoming, 'to_store', $store);

        $outgoing = StockTransfer::query()->whereIn('status', self::PENDING_TRANSFER_STATUSES);
        self::matchStore($outgoing, 'from_store', $store);

        $delivered = BranchDemandRequestLine::query()
            ->where('fulfillment_status', BranchDemandRequestLine::FULFILLMENT_DAH_DELIVERY)
            ->whereRaw('COALESCE(done_at, updated_at) >= ?', [$monthStart])
            ->whereDoesntHave('transfers')
            ->whereHas('request', function (Builder $request) use ($store) {
                $request->where('status', '!=', BranchDemandRequest::STATUS_CANCELLED);

                if ($store !== null) {
                    $request->whereRaw('LOWER(TRIM(store_code)) = ?', [mb_strtolower($store)]);
                }
            });

        $transferQty = (int) (clone $incoming)->sum('qty');
        $demandQty = (int) $delivered->sum('qty_requested');

        return [
            'receive' => [
                'qty' => $transferQty + $demandQty,
                'transfers' => (clone $incoming)->count(),
                'transfer_qty' => $transferQty,
                'demand_qty' => $demandQty,
            ],
            'branch_request' => [
                'qty' => (int) (clone $outgoing)->sum('qty'),
                'transfers' => (clone $outgoing)->count(),
            ],
        ];
    }

    /** @param  Builder<StockTransfer>  $query */
    private static function matchStore(Builder $query, string $column, ?string $store): void
    {
        if ($store !== null) {
            $query->whereRaw("LOWER(TRIM({$column})) = ?", [mb_strtolower($store)]);
        }
    }

    /**
     * @return array{slow: array{designs: int, pieces: int}, fast: array{designs: int, pieces: int}}
     */
    private static function velocity(?string $store): array
    {
        $stores = $store === null ? self::branches() : [self::canonicalBranch($store)];
        $slow = ['designs' => 0, 'pieces' => 0];
        $fast = ['designs' => 0, 'pieces' => 0];

        foreach ($stores as $code) {
            $figures = Cache::remember(self::cacheKey($code), now()->addMinutes(self::CACHE_MINUTES), fn () => self::computeVelocity($code));

            $slow['designs'] += $figures['slow']['designs'];
            $slow['pieces'] += $figures['slow']['pieces'];
            $fast['designs'] += $figures['fast']['designs'];
            $fast['pieces'] += $figures['fast']['pieces'];
        }

        return ['slow' => $slow, 'fast' => $fast];
    }

    /** Padankan huruf besar/kecil dgn kod cawangan sebenar (cth. "nilai" -> "NILAI") supaya kunci cache & padanan cermin konsisten. */
    private static function canonicalBranch(string $store): string
    {
        foreach (self::branches() as $code) {
            if (strcasecmp($code, $store) === 0) {
                return $code;
            }
        }

        return $store;
    }

    private static function cacheKey(string $store): string
    {
        return sprintf('branch-activity:v1:%s:%s', mb_strtolower($store), md5((string) InventoryPiece::query()->max('synced_at')));
    }

    /**
     * Dua query ringkas ikut index idx_mirror_store_internal_pattern (StoreCode, InternalCode, QtyOnHand, SalesDate) - tiada imbasan penuh.
     *
     * @return array{slow: array{designs: int, pieces: int}, fast: array{designs: int, pieces: int}}
     */
    private static function computeVelocity(string $store): array
    {
        $fast = DB::table((new InventoryPiece)->getTable())
            ->where('StoreCode', $store)
            ->where('SalesDate', '>=', now()->subDays(self::FAST_WINDOW_DAYS))
            ->selectRaw('InternalCode, COUNT(*) as sold')
            ->groupBy('InternalCode')
            ->havingRaw('COUNT(*) >= ?', [self::FAST_MIN_SOLD])
            ->get();

        $recentlySold = DB::table((new InventoryPiece)->getTable())
            ->where('StoreCode', $store)
            ->where('SalesDate', '>=', now()->subDays(self::SLOW_WINDOW_DAYS))
            ->distinct()
            ->pluck('InternalCode')
            ->flip();

        $slow = DB::table((new InventoryPiece)->getTable())
            ->where('StoreCode', $store)
            ->where('QtyOnHand', 1)
            ->selectRaw('InternalCode, COUNT(*) as pieces, MIN(PurchDate) as oldest')
            ->groupBy('InternalCode')
            ->havingRaw('MIN(PurchDate) < ?', [now()->subDays(self::SLOW_MIN_AGE_DAYS)])
            ->get()
            ->reject(fn (object $row) => $recentlySold->has($row->InternalCode));

        return [
            'slow' => ['designs' => $slow->count(), 'pieces' => (int) $slow->sum('pieces')],
            'fast' => ['designs' => $fast->count(), 'pieces' => (int) $fast->sum('sold')],
        ];
    }
}
