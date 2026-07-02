<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_cost' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceiptLines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function getSubtotalAttribute(): float
    {
        return $this->qty_ordered * $this->unit_cost;
    }

    public function getQtyOutstandingAttribute(): int
    {
        return max(0, $this->qty_ordered - $this->qty_received);
    }
}
