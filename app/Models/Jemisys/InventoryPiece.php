<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris = satu piece fizikal dalam TblInventory (data JEMiSys sedia ada, read-only).
 * connection 'jemisys' ditakrif dalam config/database.php - flip ke Postgres/MySQL di VM
 * nanti hanya tukar JEMISYS_DB / connection driver, model ini tak berubah.
 */
class InventoryPiece extends Model
{
    protected $connection = 'jemisys';
    protected $table = 'TblInventory';
    protected $primaryKey = 'InventoryCode';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $casts = [
        'GoldWeight' => 'decimal:2',
        'GrossWeight' => 'decimal:2',
        'TotalCost' => 'decimal:2',
        'GoldCost' => 'decimal:2',
        'McCostTotal' => 'decimal:2',
        'TagPrice' => 'decimal:2',
        'QtyOnHand' => 'integer',
        'PurchDate' => 'datetime',
        'ReceivedDate' => 'datetime',
        'SalesDate' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'VendorCode', 'VendorCode');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'CategoryCode', 'CategoryCode');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'StoreCode', 'StoreCode');
    }

    /** Stok yang masih ada sekarang. */
    public function scopeOnHand(Builder $q): Builder
    {
        return $q->where('QtyOnHand', 1);
    }

    /** Vendor sah sahaja (bukan placeholder '.' atau kosong) - sama syarat spt procurement_report.py */
    public function scopeRealVendor(Builder $q): Builder
    {
        return $q->whereNotNull('VendorCode')->whereNotIn('VendorCode', ['.', '']);
    }

    /** Kedai fizikal sahaja (kecualikan WEB/web) - sama spt analytics.py ONLINE_STORES */
    public function scopePhysicalStore(Builder $q): Builder
    {
        return $q->whereNotIn('StoreCode', ['WEB', 'web']);
    }

    /** Umur (hari) sejak PurchDate - accessor untuk paparan/sort. */
    public function getAgeDaysAttribute(): ?int
    {
        return $this->PurchDate ? (int) now()->diffInDays($this->PurchDate) : null;
    }
}
