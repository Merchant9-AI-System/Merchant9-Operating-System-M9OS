<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GoodsReceipt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (GoodsReceipt $grn) {
            if (! $grn->grn_number) {
                $grn->grn_number = static::generateGrnNumber();
            }
            $grn->received_at ??= now();
        });
    }

    public static function generateGrnNumber(): string
    {
        $year = now()->year;
        $count = static::whereYear('created_at', $year)->count() + 1;

        return sprintf('GRN-%d-%04d', $year, $count);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /**
     * Cipta GRN + line items dalam satu transaksi, kemas kini qty_received pada
     * purchase_order_lines, dan kira semula status PO. $receipts = [purchase_order_line_id => qty].
     */
    public static function receive(PurchaseOrder $po, array $receipts, string $receivedBy, ?string $notes = null): self
    {
        return DB::transaction(function () use ($po, $receipts, $receivedBy, $notes) {
            $grn = static::create([
                'purchase_order_id' => $po->id,
                'received_by' => $receivedBy,
                'notes' => $notes,
            ]);

            foreach ($receipts as $lineId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $line = PurchaseOrderLine::where('purchase_order_id', $po->id)->findOrFail($lineId);
                abort_if($line->qty_received + $qty > $line->qty_ordered, 422,
                    "Kuantiti terima ({$qty}) melebihi baki outstanding untuk {$line->internal_code}.");

                $grn->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'qty_received' => $qty,
                ]);
                $line->increment('qty_received', $qty);
            }

            $po->recalculateReceivingStatus();

            return $grn;
        });
    }
}
