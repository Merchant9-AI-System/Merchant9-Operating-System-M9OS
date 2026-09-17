<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * 1 baris caj bebas (description bebas taip + amount) di bawah 1 ExpenseClaimLine mod "Ada
 * Invois Vendor?" - cth. "Amount", "Service Tax (8%)", "Turkey Regulatory Operating Cost", dll.
 * (rujuk contoh invois Google Ads - byk baris amount di bawah 1 invois yg sama).
 */
class ExpenseClaimLineCharge extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // ExpenseClaimLine::amount sentiasa kekal sync dgn jumlah semua caj (yg lantas recalc
        // ExpenseClaim::total_amount - rujuk ExpenseClaimLine::booted()) - recalc pada setiap
        // caj ditambah/edit/padam.
        static::saved(fn (ExpenseClaimLineCharge $charge) => $charge->line->recalculateAmount());
        static::deleted(fn (ExpenseClaimLineCharge $charge) => $charge->line->recalculateAmount());
    }

    public function line()
    {
        return $this->belongsTo(ExpenseClaimLine::class, 'expense_claim_line_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }
}
