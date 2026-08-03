<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchDemandRequestLine extends Model
{
    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (BranchDemandRequestLine $line) {
            $line->line_status ??= self::STATUS_PENDING;
        });
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
