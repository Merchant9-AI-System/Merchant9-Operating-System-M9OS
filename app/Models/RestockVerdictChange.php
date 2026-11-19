<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Perubahan Cadangan sebuah design x cawangan antara dua snapshot (rujuk migration create_restock_verdict_changes_table).
 */
class RestockVerdictChange extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'changed_at' => 'datetime',
    ];
}
