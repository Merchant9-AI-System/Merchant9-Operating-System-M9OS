<?php

namespace App\Models;

use App\Models\Jemisys\Store;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BranchDemandRequest extends Model
{
    use LogsActivity;

    public const STATUS_SUBMITTED = 'Submitted';

    public const STATUS_REVIEWED = 'Reviewed';

    public const STATUS_CANCELLED = 'Cancelled';

    protected $guarded = [];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (BranchDemandRequest $request) {
            $request->request_number ??= static::generateRequestNumber();
            $request->status ??= self::STATUS_SUBMITTED;
            $request->submitted_at ??= now();
        });
    }

    public static function generateRequestNumber(): string
    {
        $year = now()->year;
        $count = static::whereYear('created_at', $year)->count() + 1;

        return sprintf('BDR-%d-%04d', $year, $count);
    }

    public function lines()
    {
        return $this->hasMany(BranchDemandRequestLine::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_code', 'StoreCode');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** Borang awam (Inertia) tiada login -> tiada submittedBy sebenar, guna nama taip tangan. */
    public function getSubmittedByLabelAttribute(): string
    {
        return $this->submittedBy?->name ?? $this->submitted_by_name ?? 'Tidak diketahui';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * Auto-route ke semua hq_reviewer - dipanggil selepas request+lines dicipta, drpd MANA2
     * permukaan (Filament CreateBranchDemandRequest::afterCreate() ATAU BranchDemandEntryController
     * Inertia) - kedua2 permukaan kongsi logik notifikasi yg SAMA supaya tak drift.
     */
    public function notifyReviewers(): void
    {
        $reviewers = User::role('hq_reviewer')->get();

        if ($reviewers->isEmpty()) {
            return;
        }

        Notification::make()
            ->title("Permintaan cawangan baharu: {$this->request_number}")
            ->body(trim((string) $this->store_code).' - '.$this->lines()->count().' item diminta oleh '.$this->submitted_by_label)
            ->info()
            ->actions([
                Action::make('gotoPage')
                    ->label('Semak')
                    ->url(route('filament.admin.resources.branch-demand-requests.view', ['record' => $this->getKey()]))
                    ->button(),
            ])
            ->sendToDatabase($reviewers);
    }

    // --- Peralihan status (role/permission-gating dikendalikan di Filament Table/Policy, bukan di sini) ---

    /** Cawangan boleh batal SEBELUM HQ mula semak mana-mana line (semua line masih Pending). */
    public function cancel(): void
    {
        abort_unless($this->status === self::STATUS_SUBMITTED, 422, 'Cuma request Submitted boleh dibatalkan.');
        abort_unless($this->lines->every(fn ($l) => $l->line_status === BranchDemandRequestLine::STATUS_PENDING), 422,
            'Request tak boleh dibatalkan sbb HQ dah mula semak sebahagian item.');

        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /** Kira semula status header ikut keputusan semua line - dipanggil selepas satu line disemak. */
    public function recalculateReviewStatus(User $reviewer): void
    {
        $this->refresh();

        if ($this->status !== self::STATUS_SUBMITTED) {
            return;
        }

        if ($this->lines->contains(fn ($l) => $l->line_status === BranchDemandRequestLine::STATUS_PENDING)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_REVIEWED,
            'reviewed_by_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
    }
}
