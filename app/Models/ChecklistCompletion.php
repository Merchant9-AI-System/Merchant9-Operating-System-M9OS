<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu item checklist yang siap bagi satu cawangan pada satu hari (hari Malaysia). */
class ChecklistCompletion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<ChecklistItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }
}
