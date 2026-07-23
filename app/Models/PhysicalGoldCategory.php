<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'name',
    'requires_branch',
    'requires_supplier',
    'requires_purity',
    'requires_date_range',
    'include_in_physical_total',
    'is_deduction',
    'active',
])]
class PhysicalGoldCategory extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'requires_branch' => 'boolean',
        'requires_supplier' => 'boolean',
        'requires_purity' => 'boolean',
        'requires_date_range' => 'boolean',
        'include_in_physical_total' => 'boolean',
        'is_deduction' => 'boolean',
        'active' => 'boolean',
    ];

    public const VALUE_MODE_GROSS_PURITY = 'gross_purity';

    public const VALUE_MODE_PAYABLE_RECEIVABLE = 'payable_receivable';

    public function lines()
    {
        return $this->hasMany(PhysicalGoldReportLine::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }
}
