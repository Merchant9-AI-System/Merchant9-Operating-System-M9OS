<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockTransfer extends Model
{
    use LogsActivity;

    public const STATUS_REQUESTED = 'Requested';

    public const STATUS_IN_TRANSIT = 'In Transit';

    public const STATUS_RECEIVED = 'Received';

    public const STATUS_CANCELLED = 'Cancelled';

    /** Alur linear (sepadan pattern advance_order Flask): Requested -> In Transit -> Received. */
    public const STATUS_FLOW = [self::STATUS_REQUESTED, self::STATUS_IN_TRANSIT, self::STATUS_RECEIVED];

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'in_transit_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockTransfer $t) {
            if (! $t->transfer_number) {
                $t->transfer_number = static::generateTransferNumber();
            }
            $t->status ??= self::STATUS_REQUESTED;
            $t->requested_at ??= now();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public static function generateTransferNumber(): string
    {
        $year = now()->year;
        $count = static::whereYear('created_at', $year)->count() + 1;

        return sprintf('TRF-%d-%04d', $year, $count);
    }

    /** Naikkan ke peringkat seterusnya dlm STATUS_FLOW. */
    public function advance(string $actor): void
    {
        $idx = array_search($this->status, self::STATUS_FLOW, true);
        abort_if(
            $idx === false || $idx >= count(self::STATUS_FLOW) - 1,
            422,
            'Transfer ni tak boleh dinaikkan status lagi.'
        );

        $next = self::STATUS_FLOW[$idx + 1];
        $update = ['status' => $next];
        if ($next === self::STATUS_IN_TRANSIT) {
            $update['in_transit_at'] = now();
        } elseif ($next === self::STATUS_RECEIVED) {
            $update['received_by'] = $actor;
            $update['received_at'] = now();
        }
        $this->update($update);
    }

    public function cancel(): void
    {
        abort_if($this->status === self::STATUS_RECEIVED, 422, 'Transfer yang dah diterima tak boleh dibatalkan.');
        $this->update(['status' => self::STATUS_CANCELLED]);
    }
}
