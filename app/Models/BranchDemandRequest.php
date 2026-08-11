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

    // Sekurang2nya SATU line APPROVED masih blm capai peringkat "tamat" (rujuk
    // BranchDemandRequestLine::FULFILLMENT_TERMINAL) - BO tgh proses (Listed/Dah Order/dll).
    public const STATUS_PROCESSING = 'Processing';

    // SEMUA line APPROVED dah capai peringkat "tamat" - tiada apa2 lagi BO perlu buat.
    public const STATUS_COMPLETED = 'Completed';

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
     * Auto-route ke semua hq_reviewer - dipanggil selepas line(s) BAHARU dicipta, drpd MANA2
     * permukaan (Filament CreateBranchDemandRequest::afterCreate() ATAU BranchDemandEntryController
     * Inertia) - kedua2 permukaan kongsi logik notifikasi yg SAMA supaya tak drift. Satu SAHAJA
     * rekod per cawangan kekal selama-lamanya (rujuk BranchDemandEntryController::store()) -
     * $isFirstEver bezakan wording "permintaan baharu" (kali pertama) drpd "item baharu
     * ditambah" (hantaran seterusnya ke rekod SEDIA ADA yg sama).
     */
    public function notifyReviewers(int $newLineCount = 0, bool $isFirstEver = true): void
    {
        $reviewers = User::role(['manager', 'ceo', 'super_admin'])->get();

        if ($reviewers->isEmpty()) {
            return;
        }

        $title = $isFirstEver
            ? "Permintaan cawangan baharu: {$this->request_number}"
            : "Item baharu ditambah: {$this->request_number}";
        $body = trim((string) $this->store_code).' - '.($newLineCount ?: $this->lines()->count()).' item diminta oleh '.$this->submitted_by_label;

        Notification::make()
            ->title($title)
            ->body($body)
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

    /** Cawangan boleh batal SEBELUM BO mula proses mana-mana line (semua line masih di peringkat
     * awal - requested/stok_kritikal, rujuk BranchDemandRequestLine::FULFILLMENT_CANCELLABLE).
     * line_status TIDAK lagi isyarat berguna utk ni (line kini AUTO-APPROVE semasa dicipta). */
    public function cancel(): void
    {
        abort_unless($this->status === self::STATUS_SUBMITTED, 422, 'Cuma request Submitted boleh dibatalkan.');
        abort_unless($this->lines->every(fn ($l) => in_array($l->fulfillment_status, BranchDemandRequestLine::FULFILLMENT_CANCELLABLE, true)), 422,
            'Request tak boleh dibatalkan sbb BO dah mula proses sebahagian item.');

        $this->update(['status' => self::STATUS_CANCELLED]);
    }
}
