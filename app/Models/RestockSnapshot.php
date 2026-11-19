<?php

namespace App\Models;

use Database\Factories\RestockSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris = satu design x cawangan (rujuk migration create_restock_snapshots_table & App\Support\RestockSnapshotMaterializer).
 * Dikira sekali selepas segerak mirror harian; halaman Restock Analysis membaca jadual ini terus.
 */
class RestockSnapshot extends Model
{
    /** @use HasFactory<RestockSnapshotFactory> */
    use HasFactory;

    /** Kunci tempoh jualan/penerimaan (lajur sold_{kunci} & received_{kunci}). */
    public const PERIODS = ['1w', '1m', '3m', '6m', '1y', 'all'];

    // computed_at menggantikan created_at/updated_at (jadual dibina semula setiap malam, bukan diedit).
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'stock' => 'integer',
        'velocity_per_month' => 'float',
        'target_stock' => 'integer',
        'gap' => 'integer',
        'cover_months' => 'float',
        'last_sold_at' => 'datetime',
        'computed_at' => 'datetime',
    ];
}
