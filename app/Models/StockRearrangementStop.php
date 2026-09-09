<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockRearrangementStop extends Model
{
    use LogsActivity, SoftDeletes;

    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    /** Status yg kekal exclude design drpd StockRearrangementRecommendation (rujuk dokblok page tsb). */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED];

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockRearrangementStop $s) {
            $s->status ??= self::STATUS_PENDING;
            $s->requested_at ??= now();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    /** Sahkan stop ni - sekadar pengesahan/audit, TIADA kesan tambahan ke exclusion (rujuk ACTIVE_STATUSES). */
    public function approve(string $actor): void
    {
        abort_unless($this->status === self::STATUS_PENDING, 422, 'Cuma rekod Pending boleh diluluskan.');

        $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_by' => $actor,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Batalkan stop ni - design akan muncul semula dlm cadangan Rearrange. Boleh drpd Pending
     * ATAU Approved. SoftDelete SERTA MERTA lepas reject (rujuk trait SoftDeletes atas) - rekod
     * terus hilang drpd senarai StockRearrangementStops, TAPI kekal dlm DB (status "Rejected"
     * disimpan sblm padam) utk audit trail, boleh disemak semula via withTrashed() kalau perlu.
     * SoftDelete di model ni HANYA utk kegunaan reject - TIADA butang "Delete" lain guna ni.
     */
    public function reject(string $actor): void
    {
        abort_if($this->trashed(), 422, 'Rekod ni dah ditolak.');

        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by' => $actor,
            'reviewed_at' => now(),
        ]);

        $this->delete();
    }
}
