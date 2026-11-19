<?php

namespace App\Models;

use Database\Factories\RestockBucketSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris = satu kategori x cawangan x bucket saiz/berat (rujuk migration create_restock_bucket_snapshots_table).
 */
class RestockBucketSnapshot extends Model
{
    /** @use HasFactory<RestockBucketSnapshotFactory> */
    use HasFactory;

    public const KIND_SIZE = 'size';

    public const KIND_WEIGHT = 'weight';

    // computed_at menggantikan created_at/updated_at (jadual dibina semula setiap malam, bukan diedit).
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'stock' => 'integer',
        'velocity_per_month' => 'float',
        'target_stock' => 'integer',
        'gap' => 'integer',
        'cover_months' => 'float',
        'computed_at' => 'datetime',
    ];
}
