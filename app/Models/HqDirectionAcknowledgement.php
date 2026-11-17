<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Akuan "Faham" ketua cawangan bagi satu arahan HQ (rujuk HqDirection::acknowledge()). */
class HqDirectionAcknowledgement extends Model
{
    public $timestamps = false;

    protected $fillable = ['hq_direction_id', 'store_code', 'acknowledged_by_id', 'acknowledged_by_name', 'acknowledged_at'];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public function direction(): BelongsTo
    {
        return $this->belongsTo(HqDirection::class, 'hq_direction_id');
    }

    /** Akuan dibuat pada hari kalendar Malaysia yang sama dgn $today. */
    public function isFromDay(Carbon $today): bool
    {
        return $this->acknowledged_at->copy()->setTimezone(HqDirection::TIMEZONE)->isSameDay($today);
    }
}
