<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bilangan design yg Sold Out / Perlu Restock di satu cawangan pada satu hari (rujuk migration create_restock_daily_counts_table).
 */
class RestockDailyCount extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'counted_on' => 'date',
        'sold_out' => 'integer',
        'restock' => 'integer',
    ];
}
