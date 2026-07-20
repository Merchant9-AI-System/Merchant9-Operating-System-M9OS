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
 * Grain jadual: SATU baris setiap (InternalCode, VendorCode) - BUKAN satu baris setiap design.
 * candidateQuery() agregat semula secara LIVE ikut vendor dipilih/dikecualikan (jadual ni cuma
 * ~39.8K baris, jadi GROUP BY/HAVING live di sini pantas, tidak spt 481K baris asal) - ini
 * membolehkan sold_count & kelayakan "stok=0" ikut serta bila supplier di-exclude/include,
 * bukan hanya tapis baris drpd senarai vendor statik (rujuk StockoutReorder utk sejarah bug ni).
 */
class StockoutReorderCandidate extends Model
{
    protected $table = 'stockout_reorder_candidates';

    // InternalCode (bukan 'id' lalai jadual mentah) - Filament CanSortRecords menambah
    // "ORDER BY <primary key>" automatik sbg tie-breaker pagination stabil (rujuk
    // vendor/filament/tables/src/Concerns/CanSortRecords.php:123-140). candidateQuery() GROUP BY
    // InternalCode sahaja, jadi ORDER BY 'id' (bukan hasil GROUP BY/aggregate) gagal di bawah
    // sql_mode=only_full_group_by. InternalCode ialah pengecam unik SEBENAR bagi setiap baris
    // HASIL candidateQuery() (walaupun jadual mentah bergrain (InternalCode,VendorCode) & guna
    // 'id' lalai utk PK fizikal - primaryKey Eloquent di sini sengaja tak sepadan PK jadual sbb
    // model ni hanya pernah dihidrat via candidateQuery(), tak pernah find()/save() baris mentah).
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
     * Agregat design (InternalCode) drpd baris per-vendor, dgn pilihan untuk hanya kira
     * vendor tertentu (include) atau kecualikan vendor tertentu (exclude) drpd sold_count/
     * qty_on_hand & ambang kelayakan "sold_count>=3 AND qty_on_hand=0".
     *
     * @param  array<int, string>  $includedVendorCodes
     * @param  array<int, string>  $excludedVendorCodes
     */
    public static function candidateQuery(array $includedVendorCodes = [], array $excludedVendorCodes = []): Builder
    {
        return static::query()
            ->select([
                'InternalCode',
                DB::raw('MAX(Description) as Description'),
                DB::raw('MAX(CategoryCode) as CategoryCode'),
                DB::raw('MAX(repair_qty_on_hand) as repair_qty_on_hand'),
                DB::raw("GROUP_CONCAT(DISTINCT VendorCode ORDER BY VendorCode SEPARATOR ',') as vendor_codes"),
                DB::raw('SUM(sold_count) as sold_count'),
                DB::raw('SUM(qty_on_hand) as qty_on_hand'),
                DB::raw('MAX(last_sale_date) as last_sale_date'),
            ])
            ->when(filled($includedVendorCodes), fn (Builder $q) => $q->whereIn('VendorCode', $includedVendorCodes))
            ->when(filled($excludedVendorCodes), fn (Builder $q) => $q->whereNotIn('VendorCode', $excludedVendorCodes))
            ->groupBy('InternalCode')
            ->havingRaw('SUM(sold_count) >= 3 AND SUM(qty_on_hand) = 0');
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

    public function hasRepairStock(): bool
    {
        return $this->repair_qty_on_hand > 0;
    }
}
