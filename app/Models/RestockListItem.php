<?php

namespace App\Models;

use Database\Factories\RestockListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Satu design dlm senarai Restock leader BO (rujuk migration create_restock_list_items_table).
 * `qty_to_order` ditetapkan leader BO (lalai = `suggested_qty` paras 3 setiap cawangan, rujuk
 * BackOfficeActionsAdvisor::restockPlan()).
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
        'ordered_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }
}
