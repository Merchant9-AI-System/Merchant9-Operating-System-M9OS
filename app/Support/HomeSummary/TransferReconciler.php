<?php

namespace App\Support\HomeSummary;

use Illuminate\Support\Carbon;

/**
 * Semak silang transfer m9os (jadual stock_transfers) dgn bukti dlm cermin JEMiSys - pengiraan tulen tanpa
 * pangkalan data, jadi mudah diuji. TIDAK mengubah data transfer (status m9os tidak dikemas kini oleh staf: mereka
 * hantar/terima dlm JEMiSys sebenar - 232 transfer aktif, tiada satu pun pernah ditanda Received).
 *
 * Bukti (aras DESIGN, kerana transfer m9os tiada nombor rujukan JEMiSys):
 * - Diterima: keping design itu di cawangan DESTINASI dgn ReceivedDate >= masa transfer diminta.
 * - Dalam perjalanan: keping design itu berstatus Transit dgn StoreCode = asal dan TransferToStore = destinasi.
 * Peruntukan FIFO: transfer disusun dari yg tertua; setiap keping hanya boleh memuaskan SATU transfer (supaya
 * penerimaan satu transfer tak dikira dua kali utk transfer lain bagi design & cawangan yg sama).
 * Had jujur: keping yg diterima daripada sumber lain (cth. pembelian atau transfer cawangan lain) bagi design yg sama
 * juga dikira bukti - TempRef1 (cawangan sumber) hanya terisi semasa transit jadi tak dapat memperketatkannya.
 *
 * Keadaan (state): diterima | dalam_perjalanan | sebahagian | belum | lapuk
 * - outstanding = qty - diterima - dalam perjalanan (tak kurang drpd 0).
 * - lapuk = masih ada baki tetapi sudah lebih STALE_DAYS hari sejak diminta (kemungkinan tak pernah dilaksanakan /
 *   sudah dibatalkan secara lisan) - dipisahkan drpd kerja yg perlu diproses sekarang.
 */
class TransferReconciler
{
    public const STATE_RECEIVED = 'diterima';

    public const STATE_IN_TRANSIT = 'dalam_perjalanan';

    public const STATE_PARTIAL = 'sebahagian';

    public const STATE_PENDING = 'belum';

    public const STATE_STALE = 'lapuk';

    /** Baki yg masih belum dipenuhi selepas ini dianggap lapuk (bukan kerja segera). */
    public const STALE_DAYS = 14;

    /** Kunci "KOD|DESTINASI" (huruf besar). */
    public static function receivedKey(string $code, string $to): string
    {
        return strtoupper(trim($code)).'|'.strtoupper(trim($to));
    }

    /** Kunci "KOD|ASAL|DESTINASI" (huruf besar). */
    public static function transitKey(string $code, string $from, string $to): string
    {
        return strtoupper(trim($code)).'|'.strtoupper(trim($from)).'|'.strtoupper(trim($to));
    }

    /**
     * @param  array<int, array{id: int, code: string, from: string, to: string, qty: int, requested_at: Carbon}>  $transfers
     * @param  array<string, array<int, Carbon>>  $received  receivedKey() => masa terima setiap keping di destinasi
     * @param  array<string, int>  $transit  transitKey() => bilangan keping berstatus Transit
     * @return array<int, array{received: int, in_transit: int, outstanding: int, state: string, age_days: int}> kunci = id transfer
     */
    public static function reconcile(array $transfers, array $received, array $transit, Carbon $now): array
    {
        usort($transfers, fn (array $a, array $b) => [$a['requested_at']->timestamp, $a['id']] <=> [$b['requested_at']->timestamp, $b['id']]);

        // Susun masa terima menaik & jejak keping yg sudah diperuntukkan.
        $pieces = [];
        foreach ($received as $key => $times) {
            $sorted = array_values($times);
            usort($sorted, fn (Carbon $a, Carbon $b) => $a->timestamp <=> $b->timestamp);
            $pieces[$key] = ['times' => $sorted, 'used' => array_fill(0, count($sorted), false)];
        }

        $transitLeft = $transit;
        $result = [];

        foreach ($transfers as $transfer) {
            $need = max((int) $transfer['qty'], 0);
            $key = self::receivedKey($transfer['code'], $transfer['to']);
            $receivedCount = 0;

            foreach ($pieces[$key]['times'] ?? [] as $index => $time) {
                if ($receivedCount >= $need) {
                    break;
                }

                if (! $pieces[$key]['used'][$index] && $time->gte($transfer['requested_at'])) {
                    $pieces[$key]['used'][$index] = true;
                    $receivedCount++;
                }
            }

            $need -= $receivedCount;

            $tKey = self::transitKey($transfer['code'], $transfer['from'], $transfer['to']);
            $inTransit = min($need, max($transitLeft[$tKey] ?? 0, 0));
            $transitLeft[$tKey] = ($transitLeft[$tKey] ?? 0) - $inTransit;
            $need -= $inTransit;

            $age = (int) $transfer['requested_at']->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

            $state = match (true) {
                $need === 0 && $inTransit > 0 => self::STATE_IN_TRANSIT,
                $need === 0 => self::STATE_RECEIVED,
                $age > self::STALE_DAYS => self::STATE_STALE,
                $receivedCount + $inTransit > 0 => self::STATE_PARTIAL,
                default => self::STATE_PENDING,
            };

            $result[$transfer['id']] = [
                'received' => $receivedCount,
                'in_transit' => $inTransit,
                'outstanding' => $need,
                'state' => $state,
                'age_days' => max($age, 0),
            ];
        }

        return $result;
    }
}
