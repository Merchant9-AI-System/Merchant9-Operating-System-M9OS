<?php

namespace App\Models;

use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'physical_gold_report_id',
    'physical_gold_category_id',
    'physical_gold_purity_id',
    'vendor_id',
    'store_code',
    'date_range_from',
    'date_range_to',
    'gross_weight',
    'pure_weight',
    'payable_pure_weight',
    'receivable_pure_weight',
    'notes',
])]
class PhysicalGoldReportLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date_range_from' => 'date',
        'date_range_to' => 'date',
        'gross_weight' => 'decimal:4',
        'pure_weight' => 'decimal:4',
        'payable_gross_weight' => 'decimal:4',
        'receivable_gross_weight' => 'decimal:4',
        'payable_pure_weight' => 'decimal:4',
        'receivable_pure_weight' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::saving(function (PhysicalGoldReportLine $line) {
            $category = $line->category;

            if (! $category) {
                return;
            }

            $factor = (float) ($line->purity?->factor ?? 1);

            if ($category->value_mode === PhysicalGoldCategory::VALUE_MODE_GROSS_PURITY) {
                $line->pure_weight = $line->gross_weight !== null
                    ? round((float) $line->gross_weight * $factor, 4)
                    : null;

                return;
            }

            // payable_receivable: borang kumpul berat KASAR payable/receivable, ditukar ke
            // tulen guna faktor ketulenan "blended" baris ni (cth. 930 -> 0.93) - sepadan
            // rawatan Stock at Branch/HQ/New Stock yg turut tiada pecahan ketulenan per-item.
            $line->payable_pure_weight = $line->payable_gross_weight !== null
                ? round((float) $line->payable_gross_weight * $factor, 4)
                : null;

            $line->receivable_pure_weight = $line->receivable_gross_weight !== null
                ? round((float) $line->receivable_gross_weight * $factor, 4)
                : null;
        });
    }

    public function physicalGoldReport()
    {
        return $this->belongsTo(PhysicalGoldReport::class);
    }

    public function category()
    {
        return $this->belongsTo(PhysicalGoldCategory::class, 'physical_gold_category_id');
    }

    public function purity()
    {
        return $this->belongsTo(PhysicalGoldPurity::class, 'physical_gold_purity_id');
    }

    /** Baca-sahaja - cermin jemisys_store_mirror, rujuk oleh kod, bukan FK (sepadan konvensyen InventoryPiece). */
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_code', 'StoreCode');
    }

    /** Baca-sahaja - cermin jemisys_vendor_mirror, rujuk oleh kod, bukan FK. */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_code', 'VendorCode');
    }
}
