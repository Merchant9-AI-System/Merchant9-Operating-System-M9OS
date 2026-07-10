<?php

namespace App\Models;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot pra-agregat StockoutReorder::baseQuery() - diisi oleh App\Support\
 * StockoutReorderMaterializer (dipanggil dari App\Jobs\SyncJemisysMirrors), BUKAN dikira live
 * setiap page load. Rujuk StockoutReorder utk kenapa perubahan ni diperlukan (91% baris
 * jemisys_inventory_mirror padan realVendor(), jadi tiada index boleh percepatkan agregat ni -
 * page tsb SATU-SATUNYA di seluruh app yg tiada caching langsung sebelum ni).
 */
class StockoutReorderCandidate extends Model
{
    protected $table = 'stockout_reorder_candidates';

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

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'VendorCode', 'VendorCode');
    }
}
