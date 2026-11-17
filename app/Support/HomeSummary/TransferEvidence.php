<?php

namespace App\Support\HomeSummary;

use App\Enums\JemisysInventoryStatus;
use App\Models\InventoryMirror;
use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Carbon;

/**
 * Bukti dlm cermin JEMiSys utk TransferReconciler (satu set query berkumpul utk semua design sekali): keping yg
 * diterima di destinasi, keping berstatus Transit, stok sedia ada di satu cawangan, dan imej design. Hanya memilih
 * lajur yg diperlukan (jadual cermin lebar; imej diambil melalui indeks meliputi - rujuk BranchSalesInsights).
 */
class TransferEvidence
{
    /**
     * @param  array<int, string>  $codes
     * @return array<string, array<int, Carbon>> TransferReconciler::receivedKey() => masa terima setiap keping
     */
    public function received(array $codes, Carbon $since): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = InventoryPiece::query()->realVendor()
            ->whereIn('InternalCode', $codes)
            ->whereNotNull('ReceivedDate')
            ->where('ReceivedDate', '>=', $since)
            ->selectRaw('RTRIM(InternalCode) AS code, UPPER(RTRIM(StoreCode)) AS store, ReceivedDate AS received_at')
            ->toBase()
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[TransferReconciler::receivedKey($row->code, $row->store)][] = Carbon::parse($row->received_at);
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, int> TransferReconciler::transitKey() => bilangan keping berstatus Transit
     */
    public function transit(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return InventoryPiece::query()->realVendor()
            ->where('Status', JemisysInventoryStatus::Transit->value)
            ->whereIn('InternalCode', $codes)
            ->selectRaw('RTRIM(InternalCode) AS code, UPPER(RTRIM(StoreCode)) AS store, UPPER(RTRIM(TransferToStore)) AS to_store, COUNT(*) AS n')
            ->groupByRaw('RTRIM(InternalCode), UPPER(RTRIM(StoreCode)), UPPER(RTRIM(TransferToStore))')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [TransferReconciler::transitKey($r->code, $r->store, $r->to_store) => (int) $r->n])
            ->all();
    }

    /**
     * SEMUA keping berstatus Transit yg menuju satu cawangan (cermin), dikumpul ikut design & cawangan asal - termasuk
     * yg tiada transfer m9os (cth. penghantaran HQ terus di JEMiSys).
     *
     * @return array<int, array{code: string, from: string, qty: int, desc: string|null}>
     */
    public function transitTo(string $store): array
    {
        return InventoryPiece::query()->realVendor()
            ->where('Status', JemisysInventoryStatus::Transit->value)
            ->whereRaw('UPPER(RTRIM(TransferToStore)) = ?', [strtoupper(trim($store))])
            ->selectRaw('RTRIM(InternalCode) AS code, UPPER(RTRIM(StoreCode)) AS from_store, COUNT(*) AS n, MAX(Description) AS description')
            ->groupByRaw('RTRIM(InternalCode), UPPER(RTRIM(StoreCode))')
            ->toBase()
            ->get()
            ->map(fn ($r) => ['code' => (string) $r->code, 'from' => (string) $r->from_store, 'qty' => (int) $r->n, 'desc' => filled($r->description) ? (string) $r->description : null])
            ->all();
    }

    /**
     * Stok sedia ada (QtyOnHand tolak keping Transit/Loan Transit yg sudah keluar secara fizikal) di SATU cawangan.
     *
     * @param  array<int, string>  $codes
     * @return array<string, int> kod design => bilangan
     */
    public function availableAt(string $store, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $store = strtoupper(trim($store));

        $onHand = InventoryPiece::query()->realVendor()
            ->whereIn('InternalCode', $codes)
            ->whereRaw('UPPER(RTRIM(StoreCode)) = ?', [$store])
            ->selectRaw('RTRIM(InternalCode) AS code, SUM(QtyOnHand) AS qty')
            ->groupByRaw('RTRIM(InternalCode)')
            ->toBase()
            ->get()
            ->pluck('qty', 'code');

        $inFlight = InventoryPiece::query()->realVendor()
            ->whereIn('Status', [
                JemisysInventoryStatus::Transit->value,
                JemisysInventoryStatus::LoanTransit->value,
                JemisysInventoryStatus::LoanReturnTransit->value,
            ])
            ->whereIn('InternalCode', $codes)
            ->whereRaw('UPPER(RTRIM(StoreCode)) = ?', [$store])
            ->selectRaw('RTRIM(InternalCode) AS code, COUNT(*) AS n')
            ->groupByRaw('RTRIM(InternalCode)')
            ->toBase()
            ->get()
            ->pluck('n', 'code');

        $result = [];

        foreach ($onHand as $code => $qty) {
            $result[(string) $code] = max((int) $qty - (int) ($inFlight[$code] ?? 0), 0);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, string> kod design => URL imej (hanya yg ada)
     */
    public function images(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return InventoryMirror::query()
            ->whereIn('InternalCode', $codes)
            ->whereNotNull('image_url')
            ->where('image_url', '<>', '')
            ->selectRaw('RTRIM(InternalCode) AS code, MAX(image_url) AS image_url')
            ->groupByRaw('RTRIM(InternalCode)')
            ->toBase()
            ->get()
            ->pluck('image_url', 'code')
            ->map(fn ($url) => (string) $url)
            ->all();
    }
}
