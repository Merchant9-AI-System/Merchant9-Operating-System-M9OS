<?php

namespace App\Models;

use App\Models\Jemisys\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BudgetPeriod extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'budget_amount' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_code', 'CategoryCode');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * Spend dikira LIVE daripada purchase_order_lines (SUM qty_ordered*unit_cost) utk PO status
     * bukan Cancelled, dlm bulan period_label ni (ikut created_at PO) - tiada jadual "spend"
     * berasingan, elak data terpisah drpd sumber sebenar.
     */
    public function getSpentAmountAttribute(): float
    {
        // whereBetween drpd sempadan bulan (bukan strftime('%Y-%m', ...) - fungsi SQLite,
        // tak wujud di MySQL) - kekal sama keputusan merentasi driver DB tanpa fungsi SQL
        // spesifik-vendor.
        $periodStart = Carbon::createFromFormat('Y-m', $this->period_label)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $query = PurchaseOrder::query()
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->with('lines');

        if ($this->category_code) {
            $query->whereHas('lines', fn ($q) => $q->where('category_code', $this->category_code));
        }

        return $query->get()->sum(function (PurchaseOrder $po) {
            $lines = $this->category_code
                ? $po->lines->where('category_code', $this->category_code)
                : $po->lines;

            return $lines->sum(fn ($l) => $l->qty_ordered * $l->unit_cost);
        });
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, $this->budget_amount - $this->spent_amount);
    }

    public function getUsagePercentAttribute(): float
    {
        return $this->budget_amount > 0 ? min(100, ($this->spent_amount / $this->budget_amount) * 100) : 0;
    }

    public function isOverBudget(): bool
    {
        return $this->spent_amount > $this->budget_amount;
    }
}
