<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu perubahan pada sasaran bulanan (rujuk BranchMonthlyTarget::record()). Hanya dicipta, tak pernah dikemas kini. */
class BranchMonthlyTargetRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_monthly_target_id', 'old_rm', 'old_grams', 'new_rm', 'new_grams', 'remark', 'source', 'changed_by_id', 'changed_by_name',
    ];

    protected $casts = [
        'old_rm' => 'decimal:2',
        'old_grams' => 'decimal:2',
        'new_rm' => 'decimal:2',
        'new_grams' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(BranchMonthlyTarget::class, 'branch_monthly_target_id');
    }
}
