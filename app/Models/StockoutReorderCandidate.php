<?php

namespace App\Models;

use App\Models\Jemisys\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot pra-agregat App\Support\StockoutReorderMaterializer (dipanggil dari
 * App\Jobs\SyncJemisysMirrors), BUKAN dikira live drpd jemisys_inventory_mirror setiap page load
 * (91% baris padan realVendor(), jadi tiada index boleh percepatkan agregat 481K baris tsb).
 *
 * Grain jadual: SATU baris setiap (InternalCode, VendorCode, StoreCode) - BUKAN satu baris
 * setiap design. candidateQuery() agregat semula secara LIVE ikut vendor/cawangan
 * dipilih/dikecualikan (jadual ni cuma ~131.8K baris, jadi GROUP BY/HAVING live di sini pantas,
 * tidak spt 481K baris asal) - ini membolehkan sold_count & kelayakan "stok=0" ikut serta bila
 * supplier/cawangan di-exclude/include, bukan hanya tapis baris drpd senarai statik (rujuk
 * StockoutReorder utk sejarah bug ni).
 *
 * "Stok Repair" & "Sold By Branch" SENGAJA TIDAK dimasukkan dlm candidateQuery() punca
 * (repairQtyOnHandFor()/soldByBranchFor() di bawah dipanggil berasingan per-rekod yg
 * dipaparkan) - percubaan awal (subquery berkorelasi ATAU leftJoinSub dlm candidateQuery()
 * sendiri) buat COUNT()/pagination candidateQuery() ambil 7-10+ saat (disahkan timing:
 * subquery bersarang utk SEMUA ~27K design walhal cuma 10-50 rekod dipaparkan setiap page),
 * DAN leftJoinSub mendedahkan 'InternalCode' dari >1 jadual serentak - pecahkan carian/susun
 * lalai Filament (rujuk error "Column 'InternalCode' in where clause is ambiguous"). Kira
 * hanya utk baris yg BENAR-BENAR dipaparkan (rujuk StockoutReorder::table() lajur
 * 'repair_qty_on_hand'/'sold_by_branch') jauh lebih murah drpd kira utk semua design.
 */
class StockoutReorderCandidate extends Model
{
    protected $table = 'stockout_reorder_candidates';

    // InternalCode (bukan 'id' lalai jadual mentah) - Filament CanSortRecords menambah
    // "ORDER BY <primary key>" automatik sbg tie-breaker pagination stabil (rujuk
    // vendor/filament/tables/src/Concerns/CanSortRecords.php:123-140). candidateQuery() GROUP BY
    // InternalCode sahaja, jadi ORDER BY 'id' (bukan hasil GROUP BY/aggregate) gagal di bawah
    // sql_mode=only_full_group_by. InternalCode ialah pengecam unik SEBENAR bagi setiap baris
    // HASIL candidateQuery() (walaupun jadual mentah bergrain (InternalCode,VendorCode,StoreCode)
    // & guna 'id' lalai utk PK fizikal - primaryKey Eloquent di sini sengaja tak sepadan PK
    // jadual sbb model ni hanya pernah dihidrat via candidateQuery(), tak pernah find()/save()
    // baris mentah).
    protected $primaryKey = 'InternalCode';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'last_sale_date' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'CategoryCode', 'CategoryCode');
    }

    /**
     * Agregat design (InternalCode) drpd baris per-(vendor,cawangan), dgn pilihan untuk hanya
     * kira vendor/cawangan tertentu (include) atau kecualikan vendor/cawangan tertentu
     * (exclude) drpd sold_count/qty_on_hand & ambang kelayakan "sold_count>=3 AND qty_on_hand=0".
     *
     * @param  array<int, string>  $includedVendorCodes
     * @param  array<int, string>  $excludedVendorCodes
     * @param  array<int, string>  $includedStoreCodes
     * @param  array<int, string>  $excludedStoreCodes
     */
    public static function candidateQuery(
        array $includedVendorCodes = [],
        array $excludedVendorCodes = [],
        array $includedStoreCodes = [],
        array $excludedStoreCodes = [],
    ): Builder {
        return static::candidateInternalCodesQuery($includedVendorCodes, $excludedVendorCodes, $includedStoreCodes, $excludedStoreCodes)
            ->addSelect([
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw("GROUP_CONCAT(DISTINCT VendorCode ORDER BY VendorCode SEPARATOR ',') as vendor_codes"),
                DB::raw('SUM(sold_count) as sold_count'),
                DB::raw('SUM(qty_on_hand) as qty_on_hand'),
                DB::raw('MAX(last_sale_date) as last_sale_date'),
            ]);
    }

    /**
     * Versi minimum candidateQuery() - hanya InternalCode (satu lajur), SAMA groupBy/having,
     * utk digunakan sbg semi-join subquery (cth. BestSellerLostOpportunityCalculator) bila
     * hanya senarai InternalCode yg layak diperlukan, bukan butiran penuh. PENTING: JANGAN guna
     * `->from('stockout_reorder_candidates')` terus utk tujuan ni - jadual mentah kini bergrain
     * (InternalCode,VendorCode,StoreCode) merangkumi SEMUA design (bukan hanya yg layak), jadi
     * rujukan terus akan sepadan set yg jauh lebih besar & salah drpd yg dimaksudkan (rujuk
     * sejarah bug: BestSellerLostOpportunityCalculator pernah rujuk jadual mentah terus lepas
     * re-grain, punca query 900+ saat - unfiltered/berulang InternalCode dlm subquery IN).
     *
     * @param  array<int, string>  $includedVendorCodes
     * @param  array<int, string>  $excludedVendorCodes
     * @param  array<int, string>  $includedStoreCodes
     * @param  array<int, string>  $excludedStoreCodes
     */
    public static function candidateInternalCodesQuery(
        array $includedVendorCodes = [],
        array $excludedVendorCodes = [],
        array $includedStoreCodes = [],
        array $excludedStoreCodes = [],
    ): Builder {
        return static::query()
            ->select('InternalCode')
            ->when(filled($includedVendorCodes), fn (Builder $q) => $q->whereIn('VendorCode', $includedVendorCodes))
            ->when(filled($excludedVendorCodes), fn (Builder $q) => $q->whereNotIn('VendorCode', $excludedVendorCodes))
            ->when(filled($includedStoreCodes), fn (Builder $q) => $q->whereIn('StoreCode', $includedStoreCodes))
            ->when(filled($excludedStoreCodes), fn (Builder $q) => $q->whereNotIn('StoreCode', $excludedStoreCodes))
            ->groupBy('InternalCode')
            ->havingRaw('SUM(sold_count) >= 3 AND SUM(qty_on_hand) = 0');
    }

    /**
     * "Stok Repair" bagi SATU design - dipanggil per-rekod yg dipaparkan (rujuk nota kelas).
     *
     * @param  array<int, string>  $includedStoreCodes
     * @param  array<int, string>  $excludedStoreCodes
     */
    public static function repairQtyOnHandFor(string $internalCode, array $includedStoreCodes = [], array $excludedStoreCodes = []): int
    {
        return (int) StockoutReorderRepairStock::query()
            ->where('InternalCode', $internalCode)
            ->when(filled($includedStoreCodes), fn (Builder $q) => $q->whereIn('StoreCode', $includedStoreCodes))
            ->when(filled($excludedStoreCodes), fn (Builder $q) => $q->whereNotIn('StoreCode', $excludedStoreCodes))
            ->sum('repair_qty');
    }

    /**
     * "Sold By Branch" (cth. ["DAMAI: 100", "WM: 42"]) bagi SATU design - dipanggil per-rekod
     * yg dipaparkan (rujuk nota kelas), SAMA penapis vendor/cawangan spt candidateQuery() supaya
     * kekal konsisten dgn sold_count.
     *
     * @param  array<int, string>  $includedVendorCodes
     * @param  array<int, string>  $excludedVendorCodes
     * @param  array<int, string>  $includedStoreCodes
     * @param  array<int, string>  $excludedStoreCodes
     * @return array<int, string>
     */
    public static function soldByBranchFor(
        string $internalCode,
        array $includedVendorCodes = [],
        array $excludedVendorCodes = [],
        array $includedStoreCodes = [],
        array $excludedStoreCodes = [],
    ): array {
        return static::query()
            ->where('InternalCode', $internalCode)
            ->when(filled($includedVendorCodes), fn (Builder $q) => $q->whereIn('VendorCode', $includedVendorCodes))
            ->when(filled($excludedVendorCodes), fn (Builder $q) => $q->whereNotIn('VendorCode', $excludedVendorCodes))
            ->when(filled($includedStoreCodes), fn (Builder $q) => $q->whereIn('StoreCode', $includedStoreCodes))
            ->when(filled($excludedStoreCodes), fn (Builder $q) => $q->whereNotIn('StoreCode', $excludedStoreCodes))
            ->select('StoreCode', DB::raw('SUM(sold_count) as branch_sold_count'))
            ->groupBy('StoreCode')
            ->orderBy('StoreCode')
            ->get()
            ->map(fn ($r) => "{$r->StoreCode}: {$r->branch_sold_count}")
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function vendorCodes(): array
    {
        return collect(explode(',', (string) $this->vendor_codes))
            ->map(fn (string $code) => trim($code))
            ->filter()
            ->values()
            ->all();
    }
}
