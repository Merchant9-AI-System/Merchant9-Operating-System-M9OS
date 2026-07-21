<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot pra-agregat App\Support\StockoutReorderMaterializer - stok item repair
 * (VendorCode='.') per (InternalCode, StoreCode). Dibaca sebagai subquery berkorelasi dlm
 * StockoutReorderCandidate::candidateQuery() supaya "Stok Repair" turut boleh dikecualikan
 * ikut cawangan, konsisten dgn sold_count/qty_on_hand.
 */
class StockoutReorderRepairStock extends Model
{
    protected $table = 'stockout_reorder_repair_stock';

    public $timestamps = false;

    protected $guarded = [];
}
