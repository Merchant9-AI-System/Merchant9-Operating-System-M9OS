<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'Draft';

    public const STATUS_PENDING_APPROVAL = 'Pending Approval';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_SENT = 'Sent';

    public const STATUS_PARTIALLY_RECEIVED = 'Partially Received';

    public const STATUS_RECEIVED = 'Received';

    public const STATUS_CANCELLED = 'Cancelled';

    /** Status yang boleh dibatalkan (sebelum barang dihantar oleh supplier). */
    public const CANCELLABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED];

    protected $guarded = [];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $po) {
            if (! $po->po_number) {
                $po->po_number = static::generatePoNumber();
            }
            $po->status ??= self::STATUS_DRAFT;
        });
    }

    public static function generatePoNumber(): string
    {
        $year = now()->year;
        $count = static::whereYear('created_at', $year)->count() + 1;

        return sprintf('PO-%d-%04d', $year, $count);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->lines->sum(fn ($l) => $l->qty_ordered * $l->unit_cost);
    }

    public function getTotalOrderedAttribute(): int
    {
        return $this->lines->sum('qty_ordered');
    }

    public function getTotalReceivedAttribute(): int
    {
        return $this->lines->sum('qty_received');
    }

    public function isFullyReceived(): bool
    {
        return $this->lines->every(fn ($l) => $l->qty_received >= $l->qty_ordered);
    }

    public function hasPartialReceipt(): bool
    {
        return $this->lines->sum('qty_received') > 0;
    }

    // --- Peralihan status (role-gating dikendalikan di Filament Resource/Policy, bukan di sini) ---

    public function submitForApproval(): void
    {
        abort_unless($this->status === self::STATUS_DRAFT, 422, 'Cuma PO Draft boleh dihantar utk kelulusan.');
        $this->update(['status' => self::STATUS_PENDING_APPROVAL]);
    }

    public function approve(string $approvedBy): void
    {
        abort_unless($this->status === self::STATUS_PENDING_APPROVAL, 422, 'Cuma PO Pending Approval boleh diluluskan.');
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);
    }

    public function markAsSent(): void
    {
        abort_unless($this->status === self::STATUS_APPROVED, 422, 'Cuma PO Approved boleh ditanda Sent.');
        $this->update(['status' => self::STATUS_SENT]);
    }

    public function cancel(): void
    {
        abort_unless(in_array($this->status, self::CANCELLABLE_STATUSES, true), 422, 'PO ni tak boleh dibatalkan pada peringkat semasa.');
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /** Kira semula status ikut qty_received semua line - dipanggil selepas GRN disimpan. */
    public function recalculateReceivingStatus(): void
    {
        $this->refresh();
        if (! in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIALLY_RECEIVED], true)) {
            return;
        }
        $this->update([
            'status' => $this->isFullyReceived() ? self::STATUS_RECEIVED : self::STATUS_PARTIALLY_RECEIVED,
        ]);
    }
}
