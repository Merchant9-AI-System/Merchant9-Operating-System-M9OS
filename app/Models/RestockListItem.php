<?php

namespace App\Models;

use Database\Factories\RestockListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Satu design dlm senarai Restock leader BO (rujuk migration create_restock_list_items_table).
 * `qty_to_order` ditetapkan leader BO (lalai = `suggested_qty` ikut paras `target_stock` setiap cawangan
 * + pengecualian `branch_targets`, rujuk BackOfficeActionsAdvisor::restockPlan()). `allocation` = agihan
 * qty_to_order ke setiap cawangan semasa Simpan (RestockAllocator).
 */
class RestockListItem extends Model
{
    /** @use HasFactory<RestockListItemFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    protected $guarded = [];

    protected $casts = [
        'qty_to_order' => 'integer',
        'suggested_qty' => 'integer',
        'target_stock' => 'integer',
        'branch_targets' => 'array',
        'allocation' => 'array',
        'ordered_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }
}
