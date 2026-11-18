<?php

namespace App\Support\HomeSummary;

use App\Models\HqDirection;
use App\Models\StockTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Data panel "Status Barang" (dahulu "Susun Semula Stok") pd /home ketua cawangan. Transfer aktif m9os disemak silang dgn cermin JEMiSys
 * (TransferReconciler) kerana status m9os tidak dikemas kini staf. Hasil:
 * - to_send: baki yg cawangan INI perlu hantar (dia sumber), tidak lapuk.
 * - incoming: dalam perjalanan / belum sampai ke cawangan ini.
 * - stale: baki lapuk (>STALE_DAYS) - dipaparkan berasingan, bukan kerja segera.
 * Jika cermin gagal, `available` = false (jangan paparkan bilangan palsu).
 */
class RearrangeFeed
{
    public const LIST_LIMIT = 12;

    /** Baris keping Transit tanpa transfer m9os yg dipaparkan (terbanyak dahulu). */
    public const MIRROR_LIST_LIMIT = 100;

    /** Sejarah "lapuk" yg dipaparkan (terbaharu dahulu). */
    public const STALE_LIST_LIMIT = 100;

    public function __construct(private TransferEvidence $evidence) {}

    /** @return array<string, mixed> */
    public function forStore(string $store): array
    {
        $store = strtoupper(trim($store));

        return Cache::remember("home.rearrange.{$store}", now()->addMinutes(15), fn () => $this->build($store));
    }

    /** @return array<string, mixed> */
    private function build(string $store): array
    {
        $empty = ['available' => true, 'to_send' => 0, 'to_send_pieces' => 0, 'incoming' => 0, 'incoming_pieces' => 0, 'stale' => 0, 'items' => [], 'receive_items' => [], 'stale_items' => []];

        try {
            $transfers = StockTransfer::query()
                ->whereIn('status', [StockTransfer::STATUS_REQUESTED, StockTransfer::STATUS_IN_TRANSIT])
                ->where(fn ($q) => $q->whereRaw('UPPER(TRIM(from_store)) = ?', [$store])->orWhereRaw('UPPER(TRIM(to_store)) = ?', [$store]))
                ->get();

            $rows = $transfers->map(fn (StockTransfer $t) => [
                'id' => $t->id,
                'code' => trim((string) $t->internal_code),
                'from' => strtoupper(trim((string) $t->from_store)),
                'to' => strtoupper(trim((string) $t->to_store)),
                'qty' => (int) $t->qty,
                'requested_at' => Carbon::parse($t->requested_at),
            ])->all();

            $codes = array_values(array_unique(array_column($rows, 'code')));
            $since = collect($rows)->min(fn ($r) => $r['requested_at']->timestamp) ?? now()->timestamp;

            $result = $rows === [] ? [] : TransferReconciler::reconcile(
                $rows,
                $this->evidence->received($codes, Carbon::createFromTimestamp($since)),
                $this->evidence->transit($codes),
                HqDirection::today(),
            );

            $out = $empty;
            $send = [];
            $receive = [];
            $stale = [];

            foreach ($transfers as $t) {
                $r = $result[$t->id];
                $from = strtoupper(trim((string) $t->from_store));

                if ($r['outstanding'] <= 0 && $r['state'] !== TransferReconciler::STATE_IN_TRANSIT) {
                    continue;   // sudah diterima di destinasi (bukti cermin)
                }

                if ($from === $store) {
                    if ($r['state'] === TransferReconciler::STATE_STALE) {
                        $out['stale']++;
                        $stale[] = [$t, $r, 'hantar'];
                    } elseif ($r['outstanding'] > 0) {
                        $out['to_send']++;
                        $out['to_send_pieces'] += $r['outstanding'];
                        $send[] = [$t, $r];
                    }
                } elseif ($r['state'] === TransferReconciler::STATE_STALE) {
                    $out['stale']++;
                    $stale[] = [$t, $r, 'terima'];
                } else {
                    $out['incoming']++;
                    $out['incoming_pieces'] += $r['outstanding'] + $r['in_transit'];
                    $receive[] = [$t, $r];
                }
            }

            // Keping Transit menuju cawangan ini yg TIADA transfer m9os (cth. HQ terus di JEMiSys): baki selepas ditolak
            // keping yg sudah dipadankan dgn transfer m9os (bukti transit). Ini yg menjadikan angka sama dgn kad Transit.
            $linked = [];
            foreach ($transfers as $t) {
                if (strtoupper(trim((string) $t->to_store)) === $store) {
                    $key = strtoupper(trim((string) $t->internal_code)).'|'.strtoupper(trim((string) $t->from_store));
                    $linked[$key] = ($linked[$key] ?? 0) + ($result[$t->id]['in_transit'] ?? 0);
                }
            }

            $mirrorOnly = [];
            foreach ($this->evidence->transitTo($store) as $line) {
                $left = $line['qty'] - ($linked[strtoupper($line['code']).'|'.$line['from']] ?? 0);

                if ($left > 0) {
                    $mirrorOnly[] = ['code' => $line['code'], 'from' => $line['from'], 'qty' => $left, 'desc' => $line['desc']];
                    $out['incoming']++;
                    $out['incoming_pieces'] += $left;
                }
            }

            usort($mirrorOnly, fn ($a, $b) => $b['qty'] <=> $a['qty']);

            // Hantar: yg paling lama menunggu dahulu. Terima: yg sudah dlm transit (bukti cermin) dahulu, kemudian yg tertua.
            usort($send, fn ($a, $b) => $b[1]['age_days'] <=> $a[1]['age_days']);
            usort($receive, fn ($a, $b) => [$b[1]['in_transit'] > 0, $b[1]['age_days']] <=> [$a[1]['in_transit'] > 0, $a[1]['age_days']]);
            // Lapuk = sejarah utk dijejak: yg terbaharu dahulu (lebih mungkin masih relevan), dihadkan.
            usort($stale, fn ($a, $b) => $a[1]['age_days'] <=> $b[1]['age_days']);
            $stale = array_slice($stale, 0, self::STALE_LIST_LIMIT);
            $send = array_slice($send, 0, self::LIST_LIMIT);
            $receive = array_slice($receive, 0, self::LIST_LIMIT);

            $mirrorOnly = array_slice($mirrorOnly, 0, self::MIRROR_LIST_LIMIT);

            $itemCodes = array_values(array_unique(array_map(fn ($i) => trim((string) $i[0]->internal_code), [...$send, ...$receive, ...$stale])));
            $stock = $this->evidence->availableAt($store, $itemCodes);
            // Imej hanya utk design transfer (senarai pendek); baris keping tanpa transfer memuatkan imej secara lazy bila
            // kelihatan (DesignImagesController).
            $images = $this->evidence->images($itemCodes);

            $map = function (array $i) use ($stock, $images) {
                [$t, $r] = $i;
                $direction = $i[2] ?? null;
                $code = trim((string) $t->internal_code);

                return [
                    'id' => $t->id,
                    'number' => $t->transfer_number,
                    'code' => $code,
                    'desc' => $t->item_desc,
                    'from' => trim((string) $t->from_store),
                    'to' => trim((string) $t->to_store),
                    'qty' => (int) $t->qty,
                    'outstanding' => $r['outstanding'],
                    'in_transit' => $r['in_transit'],
                    'state' => $r['state'],
                    'age_days' => $r['age_days'],
                    'stock' => $stock[$code] ?? 0,
                    'image' => $images[$code] ?? null,
                    'direction' => $direction,
                    'requested_at' => $t->requested_at?->format('d/m/Y'),
                    'requested_by' => $t->requested_by,
                ];
            };

            $out['items'] = array_map($map, $send);
            $out['receive_items'] = [
                ...array_map($map, $receive),
                ...array_map(fn (array $m) => [
                    'id' => 'm:'.$m['code'].':'.$m['from'],
                    'number' => null,
                    'code' => $m['code'],
                    'desc' => $m['desc'],
                    'from' => $m['from'],
                    'to' => $store,
                    'qty' => $m['qty'],
                    'outstanding' => 0,
                    'in_transit' => $m['qty'],
                    'state' => TransferReconciler::STATE_IN_TRANSIT,
                    'age_days' => null,
                    'stock' => 0,
                    'image' => null,
                    'direction' => 'terima',
                    'requested_at' => null,
                    'requested_by' => null,
                    'source' => 'jemisys',
                ], $mirrorOnly),
            ];
            $out['stale_items'] = array_map($map, $stale);

            return $out;
        } catch (Throwable $e) {
            report($e);

            return ['available' => false] + $empty;
        }
    }
}
