<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ExpenseClaimLine extends Model
{
    use LogsActivity;

    /**
     * Kategori cadangan lalai (tags-input di borang - rujuk Create.vue) - `category` kini
     * FREE TEXT (staf boleh taip & cipta kategori baharu sendiri terus dlm tags-input, BUKAN
     * enum tertutup macam asal), senarai ni sekadar cadangan awal + suggestion. Nilai TERUS
     * teks paparan (bukan slug) - tiada lagi pasangan kod/label berasingan.
     * "Digital Marketing / Ads" ditambah (drpd asal "Others") - kategori ads (Google/Facebook/
     * TikTok) kini cukup kerap utk kategori sendiri, bukan terus lumba ke "Others".
     *
     * @var list<string>
     */
    public const DEFAULT_CATEGORIES = [
        'Petrol',
        'Toll & Parking',
        'Stationery',
        'Car Maintenance',
        'Medical',
        'Entertainment / Refreshment',
        'Shop Expenses',
        'Digital Marketing / Ads',
        'Others',
    ];

    protected $guarded = [];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'is_vendor_invoice' => 'boolean',
    ];

    protected static function booted(): void
    {
        // total_amount header (ExpenseClaim) sentiasa kekal sync dgn jumlah lines - recalc pada
        // setiap perubahan (tambah/edit/padam), bukan dikira on-the-fly setiap paparan.
        static::saved(fn (ExpenseClaimLine $line) => $line->claim->recalculateTotal());
        static::deleted(fn (ExpenseClaimLine $line) => $line->claim->recalculateTotal());
    }

    public function claim()
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    /** Baris caj bebas (description bebas taip + amount) - hanya utk line mod "Ada Invois
     * Vendor?" (rujuk ExpenseClaimLineCharge dokblok). Line kategori biasa TIADA caj (amount
     * ditaip terus, tak disentuh mekanisme ni). */
    public function charges()
    {
        return $this->hasMany(ExpenseClaimLineCharge::class, 'expense_claim_line_id');
    }

    /** Jumlahkan semula amount drpd semua caj - dipanggil bila caj berubah (rujuk
     * ExpenseClaimLineCharge::booted()). Line kategori biasa (tiada caj) TIDAK pernah dipanggil
     * kaedah ni, amount kekal nilai yg ditaip terus semasa cipta/kemaskini. */
    public function recalculateAmount(): void
    {
        $this->update(['amount' => $this->charges()->sum('amount')]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }
}
