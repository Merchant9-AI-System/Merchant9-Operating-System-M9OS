<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cawangan sasaran sesuatu arahan HQ (rujuk HqDirection). */
class HqDirectionBranch extends Model
{
    public $timestamps = false;

    protected $fillable = ['hq_direction_id', 'store_code'];

    public function direction(): BelongsTo
    {
        return $this->belongsTo(HqDirection::class, 'hq_direction_id');
    }
}
