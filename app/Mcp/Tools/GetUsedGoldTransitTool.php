<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Emas "used gold"/trade-in yang BELUM diterima/disahkan di HQ - domain BERASINGAN drpd
 * TblInventory (jewelry siap, semua tool lain dlm server ni). Query LIVE terus ke 'jemisys'
 * (tiada mirror tempatan utk trade-in).
 *
 * MEKANISME DISAHKAN TERUS drpd padanan REKOD SEBENAR (bukan teka drpd nama lajur) - dibanding
 * satu-satu dgn skrin JEMiSys "Receive Trade-In Goods" (filter "Not Received", 01/09-29/09/2026,
 * 653 rekod) sehingga tepat sepadan:
 * - `TblTradeInDetail` (BUKAN `TradeInItemReceived` - table tu cermin lain yg TIADA baris utk
 *   item yg masih pending, sentiasa Received='Y') - baris keping SEBENAR, Received='N' =
 *   belum disahkan sampai di HQ.
 * - `TblTradeIn` (header, JOIN via RefNo+StoreCode) - Status header PENTING sbg tapisan kedua,
 *   BUKAN sekadar Received di baris detail sahaja (tanpa ni dpt 811, bukan 653) -
 *   '0' = benar-benar outstanding/belum diproses (SEPADAN 653 rekod skrin sebenar),
 *   'V' = void/ditolak (~85 rekod dlm sesi ujian - sepadan radio "Rejected" di skrin),
 *   'C' = dah selesai/closed (~72 rekod). Tool ni SENGAJA hanya Status='0' - sepadan definisi
 *   "Not Received" skrin, BUKAN "Rejected"/"Closed".
 */
#[Name('get-used-gold-transit')]
#[Description('Emas trade-in (used gold) yang belum diterima/disahkan di HQ - pecahan ikut cawangan asal, bilangan item & jumlah berat, dlm julat tarikh pilihan.')]
#[IsReadOnly]
class GetUsedGoldTransitTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'store_code' => ['nullable', 'string'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $storeCode = $validated['store_code'] ?? null;

        $rows = DB::connection('jemisys')->table('TblTradeInDetail as D')
            ->join('TblTradeIn as H', function ($join) {
                $join->on('H.RefNo', '=', 'D.RefNo')->on('H.StoreCode', '=', 'D.StoreCode');
            })
            ->where('D.Received', 'N')
            ->where('H.Status', '0')
            ->whereBetween('H.TransDate', [$dateFrom, $dateTo.' 23:59:59'])
            ->when($storeCode, fn ($q, $code) => $q->whereRaw('TRIM(D.StoreCode) = ?', [trim($code)]))
            ->selectRaw('D.StoreCode, COUNT(*) as item_count, SUM(D.GoldWeight) as total_gold_weight')
            ->groupBy('D.StoreCode')
            ->orderByDesc('total_gold_weight')
            ->get();

        $byBranch = $rows->map(fn ($r) => [
            'store_code' => trim($r->StoreCode),
            'item_count' => (int) $r->item_count,
            'total_gold_weight' => round((float) $r->total_gold_weight, 2),
        ])->values()->all();

        return Response::structured([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_items' => (int) $rows->sum('item_count'),
            'total_gold_weight' => round((float) $rows->sum('total_gold_weight'), 2),
            'by_branch' => $byBranch,
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()
                ->description('Tarikh mula (YYYY-MM-DD) - lalai awal bulan semasa.'),
            'date_to' => $schema->string()
                ->description('Tarikh akhir (YYYY-MM-DD) - lalai hari ini.'),
            'store_code' => $schema->string()
                ->description('Pilihan - hadkan kpd SATU cawangan asal (cth. "TTDI"). Kosong = semua cawangan.'),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->required(),
            'date_to' => $schema->string()->required(),
            'total_items' => $schema->integer()->description('Jumlah item trade-in belum diterima/disahkan di HQ.')->required(),
            'total_gold_weight' => $schema->number()->description('Jumlah berat emas (gram) belum diterima/disahkan.')->required(),
            'by_branch' => $schema->array()->description('Pecahan ikut cawangan asal, susun ikut berat menurun.')->required(),
        ];
    }
}
