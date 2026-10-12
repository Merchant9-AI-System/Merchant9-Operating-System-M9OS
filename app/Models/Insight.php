<?php

namespace App\Models;

use App\Enums\InsightAgent;
use App\Enums\InsightSeverity;
use Database\Factories\InsightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu insight yg dihasilkan agent AI (rujuk migration create_insights_table).
 * `dedupe_key` (pilihan) - kunci stabil utk elak insight sama diulang tiap kali agent jalan
 * (cth. "procurement:low-stock:CE:KL").
 */
class Insight extends Model
{
    /** @use HasFactory<InsightFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'agent' => InsightAgent::class,
        'severity' => InsightSeverity::class,
        'figures' => 'array',
        'sources' => 'array',
        'data_as_of' => 'datetime',
    ];
}
