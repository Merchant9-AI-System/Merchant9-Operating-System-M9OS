<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Senarai InternalCode LAYAK jadi calon reorder ikut definisi LALAI (semua vendor/cawangan
 * dikira) - jadual kecil unik-key (PK InternalCode) semata-mata utk semi-join MURAH bagi
 * App\Support\BestSellerLostOpportunityCalculator (rujuk migration create_..._table utk sejarah
 * kenapa stockout_reorder_candidates [grain per-vendor-per-cawangan] tak lagi sesuai utk tujuan
 * ni). Diisi oleh App\Support\StockoutReorderMaterializer.
 */
class StockoutReorderQualifyingDesign extends Model
{
    protected $table = 'stockout_reorder_qualifying_designs';

    protected $primaryKey = 'InternalCode';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
