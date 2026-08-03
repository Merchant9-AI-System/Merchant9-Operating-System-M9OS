<?php

namespace App\Support;

use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use Illuminate\Support\Collection;

/**
 * Module D (Automatic Stock Allocation Suggestion) - cadang cawangan mana patut bekalkan
 * demand cawangan yg DAH DILULUSKAN HQ (App\Models\BranchDemandRequestLine, line_status
 * Approved & masih ada qty_outstanding). BERBEZA drpd StockRearrangementRecommender (yg
 * INFERENS "sold out" drpd data jualan) - ni permintaan EKSPLISIT drpd cawangan sendiri,
 * jadi SENGAJA kelas berasingan (bukan reuse StockRearrangementRecommender yg kekal pada
 * kontrak "1 unit sahaja per design" - demand cawangan perlukan kuantiti berubah2 ikut
 * qty_outstanding sebenar).
 *
 * Rule sama style drpd StockRearrangementRecommender: donor perlu stok >=3 (baki >=2 kekal
 * simpanan), donor tak boleh sama dgn cawangan yg memohon. TIADA tulis ke DB - papar sahaja,
 * transfer sebenar dicipta bila HQ confirm via "Cipta Transfer" (rujuk
 * App\Filament\Pages\BranchDemandAllocationSuggestion). Tiada caching (rememberForever) di sini
 * - tak spt kalkulator category-wide yg berat, bilangan demand line diluluskan+blm dipenuhi
 * pd bila2 masa amat kecil (skala puluhan, bukan ribuan), jadi kos kira semula setiap page
 * load boleh diabaikan, dan mengelak isu cache basi bila status/transfer berubah.
 */
class BranchDemandAllocationRecommender
{
    public static function recommendations(): Collection
    {
        $lines = BranchDemandRequestLine::query()
            ->where('line_status', BranchDemandRequestLine::STATUS_APPROVED)
            ->with('request', 'transfers')
            ->get()
            ->filter(fn ($line) => $line->qty_outstanding > 0)
            ->values();

        if ($lines->isEmpty()) {
            return collect();
        }

        $codes = $lines->pluck('internal_code')->unique()->values();

        $stockByCode = InventoryPiece::query()
            ->realVendor()
            ->physicalStore()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, StoreCode, SUM(QtyOnHand) as stock')
            ->groupBy('InternalCode', 'StoreCode')
            ->get()
            ->groupBy('InternalCode');

        $meta = InventoryPiece::query()
            ->whereIn('InternalCode', $codes)
            ->selectRaw('InternalCode, MAX(Description) as Description, MAX(CategoryCode) as CategoryCode')
            ->groupBy('InternalCode')
            ->get()
            ->keyBy('InternalCode');

        $categoryNames = Category::pluck('Description', 'CategoryCode');

        return $lines
            ->map(function ($line) use ($stockByCode, $meta, $categoryNames) {
                $requestingBranch = trim((string) $line->request->store_code);

                $donor = ($stockByCode->get($line->internal_code) ?? collect())
                    ->filter(fn ($r) => trim((string) $r->StoreCode) !== $requestingBranch)
                    ->sortByDesc('stock')
                    ->first(fn ($r) => (int) $r->stock >= 3);

                if (! $donor) {
                    return null;
                }

                $qty = min($line->qty_outstanding, (int) $donor->stock - 2);

                if ($qty < 1) {
                    return null;
                }

                $m = $meta->get($line->internal_code);

                return [
                    'branch_demand_request_line_id' => $line->id,
                    'request_number' => $line->request->request_number,
                    'internal_code' => $line->internal_code,
                    'item_desc' => $m->Description ?? $line->item_desc,
                    'category_name' => $categoryNames[$m->CategoryCode ?? ''] ?? '',
                    'from_branch' => trim((string) $donor->StoreCode),
                    'to_branch' => $requestingBranch,
                    'current_stock' => (int) $donor->stock,
                    'qty_outstanding' => $line->qty_outstanding,
                    'suggested_qty' => $qty,
                    'reason' => "Cawangan {$requestingBranch} memohon {$line->qty_outstanding} unit blm dipenuhi ({$line->request->request_number}) - {$donor->StoreCode} ada {$donor->stock} unit",
                    'suggestion' => "{$qty} unit: {$donor->StoreCode} -> {$requestingBranch}",
                ];
            })
            ->filter()
            ->values();
    }
}
