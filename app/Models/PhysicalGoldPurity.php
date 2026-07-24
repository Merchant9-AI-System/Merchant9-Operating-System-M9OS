<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['purity', 'factor', 'active'])]
class PhysicalGoldPurity extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'factor' => 'decimal:4',
        'active' => 'boolean',
        'is_base_purity' => 'boolean',
    ];

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
