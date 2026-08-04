<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchDemandRequestLine extends Model
{
    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    /** Line biasa - internal_code sebenar drpd search() dalaman (jemisys_inventory_mirror). */
    public const SOURCE_CATALOG = 'catalog';

    /** Line drpd cadangan carian laman web (MerchantWebsiteSearch) - TIADA internal_code
     * dipercayai, HQ padankan ke stok sebenar semasa semakan (rujuk ViewBranchDemandRequest). */
    public const SOURCE_WEB = 'web';

    /** Staf cawangan langkau carian terus, ATAU carian dalaman+laman web dua2 TIADA hasil -
     * muat naik gambar sendiri & taip keterangan manual (rujuk ProductPicker.vue "selectManual").
     * TIADA internal_code, SAMA spt SOURCE_WEB, HQ padankan ke stok sebenar semasa semakan. */
    public const SOURCE_UPLOAD = 'upload';

    // --- fulfillment_status: landasan progress BO SENDIRI, BERASINGAN drpd line_status
    // (keputusan Lulus/Tolak HQ, kekal x berubah - rujuk review()). BO kemaskini medan ni dari
    // semasa ke semasa (sesetengah permintaan makan 2-3 hari bekerja), staf cawangan nampak
    // progress tsb (rujuk RequestList.vue). FULFILLMENT_STOK_KRITIKAL turut dicetuskan TERUS
    // oleh toggle "Kritikal" staf cawangan semasa hantar (rujuk BranchDemandEntryController::
    // store() - satu mekanisme sahaja, bukan flag berasingan).
    public const FULFILLMENT_REQUESTED = 'requested';

    public const FULFILLMENT_STOK_KRITIKAL = 'stok_kritikal';

    public const FULFILLMENT_SPECIAL_REQUEST = 'special_request';

    public const FULFILLMENT_LISTED_NOTED = 'listed_noted';

    public const FULFILLMENT_DAH_ORDER = 'dah_order';

    public const FULFILLMENT_DAH_RESTOCK = 'dah_restock';

    public const FULFILLMENT_DAH_DELIVERY = 'dah_delivery';

    public const FULFILLMENT_REARRANGE = 'rearrange';

    public const FULFILLMENT_ORDER = 'order';

    public const FULFILLMENT_ITEM_NOT_AVAILABLE = 'item_not_available';

    /** Label paparan (BO/cawangan) - urutan padan senarai asal BO. @var array<string, string> */
    public const FULFILLMENT_LABELS = [
        self::FULFILLMENT_REQUESTED => 'Requested',
        self::FULFILLMENT_STOK_KRITIKAL => 'Stok Kritikal',
        self::FULFILLMENT_SPECIAL_REQUEST => 'Special Request',
        self::FULFILLMENT_LISTED_NOTED => 'Listed / Noted',
        self::FULFILLMENT_DAH_ORDER => 'Dah Order',
        self::FULFILLMENT_DAH_RESTOCK => 'Dah Restock',
        self::FULFILLMENT_DAH_DELIVERY => 'Dah Delivery',
        self::FULFILLMENT_REARRANGE => 'Rearrange',
        self::FULFILLMENT_ORDER => 'Order',
        self::FULFILLMENT_ITEM_NOT_AVAILABLE => 'Item Not Available',
    ];

    /** Peringkat "tamat" - tiada apa2 lagi BO perlu buat utk line tsb (rujuk
     * BranchDemandRequest::recalculateFulfillmentStatus() - request keseluruhan jadi "Selesai"
     * bila SEMUA line APPROVED capai salah satu peringkat ni). @var array<int, string> */
    public const FULFILLMENT_TERMINAL = [
        self::FULFILLMENT_DAH_DELIVERY,
        self::FULFILLMENT_REARRANGE,
        self::FULFILLMENT_ITEM_NOT_AVAILABLE,
    ];

    /** Warna badge (Filament) setiap peringkat. @var array<string, string> */
    public const FULFILLMENT_COLORS = [
        self::FULFILLMENT_REQUESTED => 'gray',
        self::FULFILLMENT_STOK_KRITIKAL => 'danger',
        self::FULFILLMENT_SPECIAL_REQUEST => 'info',
        self::FULFILLMENT_LISTED_NOTED => 'gray',
        self::FULFILLMENT_DAH_ORDER => 'warning',
        self::FULFILLMENT_DAH_RESTOCK => 'warning',
        self::FULFILLMENT_DAH_DELIVERY => 'success',
        self::FULFILLMENT_REARRANGE => 'warning',
        self::FULFILLMENT_ORDER => 'warning',
        self::FULFILLMENT_ITEM_NOT_AVAILABLE => 'danger',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (BranchDemandRequestLine $line) {
            $line->line_status ??= self::STATUS_PENDING;
            $line->fulfillment_status ??= self::FULFILLMENT_REQUESTED;
        });
    }

    public function getFulfillmentLabelAttribute(): string
    {
        return self::FULFILLMENT_LABELS[$this->fulfillment_status] ?? $this->fulfillment_status;
    }

    public function request()
    {
        return $this->belongsTo(BranchDemandRequest::class, 'branch_demand_request_id');
    }

    /** Transfer yg dicipta utk penuhi line ni (boleh >1 - cth. dipenuhi sebahagian drpd 2 cawangan). */
    public function transfers()
    {
        return $this->hasMany(StockTransfer::class);
    }

    /** Kuantiti yg dah "commit" via StockTransfer (kecuali yg dibatalkan). */
    public function getQtyFulfilledAttribute(): int
    {
        return (int) $this->transfers()->where('status', '!=', StockTransfer::STATUS_CANCELLED)->sum('qty');
    }

    /** Baki blm dipenuhi - hanya relevan utk line yg dah diluluskan. */
    public function getQtyOutstandingAttribute(): int
    {
        if ($this->line_status !== self::STATUS_APPROVED) {
            return 0;
        }

        return max(0, (int) $this->qty_approved - $this->qty_fulfilled);
    }

    /** Semak line ini - HQ boleh luluskan (penuh/sebahagian) atau tolak sepenuhnya. */
    public function review(string $status, ?int $qtyApproved, ?string $notes): void
    {
        abort_unless($this->line_status === self::STATUS_PENDING, 422, 'Line ini dah disemak.');
        abort_unless(in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true), 422, 'Status tidak sah.');

        $this->update([
            'line_status' => $status,
            'qty_approved' => $status === self::STATUS_APPROVED ? $qtyApproved : 0,
            'review_notes' => $notes,
        ]);
    }
}
