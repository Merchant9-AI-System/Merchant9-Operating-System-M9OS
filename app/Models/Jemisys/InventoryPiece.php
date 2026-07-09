<?php

namespace App\Models\Jemisys;

use Database\Factories\Jemisys\InventoryPieceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris = satu piece fizikal dalam TblInventory (data JEMiSys). Baca drpd cermin tempatan
 * `jemisys_inventory_mirror` (DB lalai) - BUKAN terus live 'jemisys' SQL Server - sbb TblInventory
 * (481K baris) tiada index sesuai kat live server & job jadual jemisys gantikan seluruh DB secara
 * berkala (index custom akan hilang). Cermin ni disegerak scr berkala/manual via
 * App\Jobs\SyncJemisysMirrors (rujuk butang "Segerak Data JEMiSys" pada
 * JemisysConnectionStatus). Struktur/nama lajur 1:1 sama dgn TblInventory, jadi scope/relationship
 * di bawah tak berubah langsung drpd sebelum migrate ke cermin. vendor()/category()/store()
 * kekal sbg relationship biasa - Vendor/Category/Store turut disegerak ke cermin tempatan
 * sendiri (jemisys_vendor_mirror/jemisys_category_mirror/jemisys_store_mirror) drpd job yg
 * sama, supaya relationship ni kekal pd SAMBUNGAN SAMA (elak ralat cross-connection bila lajur
 * relation di-searchable()/sortable() dlm Filament).
 */
class InventoryPiece extends Model
{
    /** @use HasFactory<InventoryPieceFactory> */
    use HasFactory;

    protected $table = 'jemisys_inventory_mirror';

    protected $primaryKey = 'InventoryCode';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    // Cermin baca-sahaja (data hanya masuk via SyncJemisysMirrors::insert() terus,
    // bukan Eloquent) - $guarded=[] sekadar benarkan factory/fixture ujian guna create() terus.
    protected $guarded = [];

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
