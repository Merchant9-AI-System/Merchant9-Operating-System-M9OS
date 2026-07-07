<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rekod audit append-only utk DailyAssetPosition - tiada updated_at (log tak pernah diubah).
 */
class DailyAssetPositionAudit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function dailyAssetPosition()
    {
        return $this->belongsTo(DailyAssetPosition::class);
    }
}
