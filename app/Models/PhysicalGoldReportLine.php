<?php

namespace App\Models;

use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use Illuminate\Database\Eloquent\Model;

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
        'workmanship_amount' => 'decimal:4',
        'gold_price_per_gram' => 'decimal:4',
        'gold_amount' => 'decimal:4',
        'total_price' => 'decimal:4',
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

                // Utk New Stock Not Yet Key-in - Gold Amount & Total Price sentiasa dikira
                // semula (bukan input terus), sepadan lajur Weekly Stock Report sebenar.
                $line->gold_amount = ($line->gross_weight !== null && $line->gold_price_per_gram !== null)
                    ? round((float) $line->gross_weight * (float) $line->gold_price_per_gram, 4)
                    : null;

                $line->total_price = ($line->workmanship_amount !== null || $line->gold_amount !== null)
                    ? round((float) ($line->workmanship_amount ?? 0) + (float) ($line->gold_amount ?? 0), 4)
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
