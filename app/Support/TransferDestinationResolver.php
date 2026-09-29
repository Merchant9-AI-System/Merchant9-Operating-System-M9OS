<?php

namespace App\Support;

use App\Enums\JemisysInventoryStatus;
use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cari cawangan DESTINASI (StoreCodeTo) piece yg sedang Transit/Loan Transit - dgn join
 * TempRef1 (cawangan sumber) + TempRef2 (RefNo/DO) piece tu ke TblTransfer LIVE (RefNo
 * bukan unik sepanjang masa - berulang antara tempoh berlainan, jadi wajib tapis jugak
 * StoreCodeFrom = TempRef1 & ambil TransDate paling baru utk elak padanan silap).
 */
class TransferDestinationResolver
{
    /** Status yg bermaksud item sedang bergerak antara cawangan. */
    private const IN_TRANSIT_STATUSES = [
        JemisysInventoryStatus::Transit->value,
        JemisysInventoryStatus::LoanTransit->value,
        JemisysInventoryStatus::LoanReturnTransit->value,
    ];

    public static function resolve(InventoryPiece $piece): ?string
    {
        if (! in_array(trim((string) $piece->Status), self::IN_TRANSIT_STATUSES, true)) {
            return null;
        }

        $sourceStore = trim((string) $piece->TempRef1);
        $refNo = trim((string) $piece->TempRef2);

        if ($sourceStore === '' || $refNo === '') {
            return null;
        }

        try {
            $row = DB::connection('jemisys')->selectOne(
                'SELECT TOP 1 StoreCodeTo FROM TblTransfer WHERE RefNo = ? AND StoreCodeFrom = ? ORDER BY TransDate DESC',
                [$refNo, $sourceStore]
            );

            return $row?->StoreCodeTo;
        } catch (\Throwable $e) {
            // Sambungan live JEMiSys kadang timeout/down - gagal senyap, papar '-' drpd throw.
            Log::warning('TransferDestinationResolver: gagal cari StoreCodeTo', [
                'inventory_code' => $piece->InventoryCode,
                'ref_no' => $refNo,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
