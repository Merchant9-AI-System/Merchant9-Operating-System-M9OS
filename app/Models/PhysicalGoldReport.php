<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'report_date',
    'prepared_by_id',
    'prepared_by',
    'cutoff_at',
    'submitted_at',
    'submitted_by_id',
    'submitted_by',
    'approved_at',
    'approved_by_id',
    'approved_by',
    'notes',
    'status',
])]
class PhysicalGoldReport extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_DRAFT = 'Draft';

    public const STATUS_SUBMITTED = 'Submitted';

    public const STATUS_APPROVED = 'Approved';

    /** Susunan peringkat status - insert sahaja bila tambah status baru pd masa depan (cth. Under Review). */
    public const STATUS_FLOW = [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED];

    protected $guarded = [];

    protected $casts = [
        'report_date' => 'date',
        'cutoff_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PhysicalGoldReport $report) {
            $report->status ??= self::STATUS_DRAFT;
        });
    }

    public function lines()
    {
        return $this->hasMany(PhysicalGoldReportLine::class);
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    // --- Peralihan status (role/permission-gating dikendalikan di Filament Table, bukan di sini) ---

    public function submit(User $user): void
    {
        abort_unless($this->status === self::STATUS_DRAFT, 422, 'Cuma laporan Draft boleh dihantar.');

        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'submitted_by_id' => $user->id,
            'submitted_by' => $user->name,
            'submitted_at' => now(),
        ]);
    }

    public function approve(User $user): void
    {
        abort_unless($this->status === self::STATUS_SUBMITTED, 422, 'Cuma laporan Submitted boleh diluluskan.');

        // Kawalan maker-checker teras - penyedia laporan TIDAK boleh meluluskan laporan sendiri.
        // Diperkuat di sini (bukan cuma di Table action ->visible()) sbg pertahanan berlapis,
        // sbb ini peraturan baru tanpa contoh sedia ada di PurchaseOrder/StockTransfer.
        abort_if($this->prepared_by_id === $user->id, 403, 'Penyedia laporan tidak boleh meluluskan laporan sendiri.');

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by_id' => $user->id,
            'approved_by' => $user->name,
            'approved_at' => now(),
        ]);
    }

    public function reject(User $user): void
    {
        abort_unless($this->status === self::STATUS_SUBMITTED, 422, 'Cuma laporan Submitted boleh ditolak.');

        $this->update([
            'status' => self::STATUS_DRAFT,
            'submitted_by_id' => null,
            'submitted_by' => null,
            'submitted_at' => null,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }
}
