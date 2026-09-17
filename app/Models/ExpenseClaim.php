<?php

namespace App\Models;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ExpenseClaim extends Model
{
    use LogsActivity;

    public const STATUS_DRAFT = 'Draft';

    public const STATUS_SUBMITTED = 'Submitted';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    /** Kategori boleh ditambah/edit/padam bila claim di peringkat ni sahaja. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    protected $guarded = [];

    protected $casts = [
        'claim_month' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (ExpenseClaim $claim) {
            $claim->claim_number ??= static::generateClaimNumber();
            $claim->status ??= self::STATUS_DRAFT;
        });
    }

    public static function generateClaimNumber(): string
    {
        $year = now()->year;
        $count = static::whereYear('created_at', $year)->count() + 1;

        return sprintf('CLM-%d-%04d', $year, $count);
    }

    public function lines()
    {
        return $this->hasMany(ExpenseClaimLine::class);
    }

    /** Claimant - org yg expense ni untuk (cth. CEO En Haniff). BUKAN semestinya org yg key-in
     * claim sendiri (rujuk createdBy()). */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Staf Finance (cth. Aqilah) yg SEBENARNYA cipta/kemaskini claim ni bagi pihak claimant. */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    /** Jumlahkan semula total_amount drpd semua lines - dipanggil setiap kali line berubah (rujuk ExpenseClaimLine::booted()). */
    public function recalculateTotal(): void
    {
        $this->update(['total_amount' => $this->lines()->sum('amount')]);
    }

    /**
     * Peta nama role sistem (Spatie, cth. 'ceo') -> label jawatan yg lebih formal utk papar pd
     * claim (rujuk ExpenseClaimController::edit() - cuma cadangan AUTO-ISI bila medan "Jawatan"
     * masih kosong, staf Finance tetap boleh timpa dgn jawatan sebenar kalau label ni tak tepat).
     *
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        'super_admin' => 'Super Admin',
        'ceo' => 'CEO',
        'manager' => 'Pengurus',
        'branch_staff' => 'Staf Cawangan',
        'hq_reviewer' => 'Penyemak HQ',
        'finance' => 'Staf Kewangan',
        'head_finance' => 'Ketua Kewangan',
    ];

    /** Label jawatan cadangan drpd role SISTEM claimant (role pertama, jika lebih drpd satu). */
    public function claimantRoleLabel(): ?string
    {
        $role = $this->user?->getRoleNames()->first();

        if (! $role) {
            return null;
        }

        return self::ROLE_LABELS[$role] ?? Str::headline($role);
    }

    // --- Peralihan status ---

    /** Staf hantar claim bulan ni utk kelulusan - wajib sekurang-kurangnya 1 line. */
    public function submit(): void
    {
        abort_unless(in_array($this->status, self::EDITABLE_STATUSES, true), 422, 'Claim ni tak boleh dihantar drpd status semasa.');
        abort_if($this->lines()->count() === 0, 422, 'Tambah sekurang-kurangnya 1 item sebelum hantar claim.');

        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->notifyApprovers();
    }

    public function approve(User $actor): void
    {
        abort_unless($this->status === self::STATUS_SUBMITTED, 422, 'Cuma claim Submitted boleh diluluskan.');

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by_id' => $actor->id,
            'approved_at' => now(),
        ]);

        $this->notifyCreator('Claim diluluskan', "Claim {$this->claim_number} ({$this->claimant_name}) telah diluluskan oleh {$actor->name}.");
    }

    public function reject(User $actor, string $reason): void
    {
        abort_unless($this->status === self::STATUS_SUBMITTED, 422, 'Cuma claim Submitted boleh ditolak.');

        $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by_id' => $actor->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->notifyCreator('Claim ditolak', "Claim {$this->claim_number} ({$this->claimant_name}) ditolak oleh {$actor->name}: {$reason}. Sila kemaskini & hantar semula.");
    }

    /** head_finance SATU-SATUNYA approver (+ super_admin override) - BUKAN lagi manager/ceo
     * (rujuk restructure: claimant boleh jadi CEO sendiri, konflik kalau CEO approve claim
     * sendiri/rakan sejawat). */
    public function notifyApprovers(): void
    {
        $approvers = User::role(['head_finance', 'super_admin'])->get();

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::make()
            ->title("Claim baharu menunggu kelulusan: {$this->claim_number}")
            ->body("{$this->claimant_name} - RM ".number_format((float) $this->total_amount, 2).' untuk bulan '.$this->claim_month->format('F Y'))
            ->info()
            ->actions([
                Action::make('gotoPage')
                    ->label('Semak')
                    ->url(route('filament.admin.resources.expense-claims.view', ['record' => $this->getKey()]))
                    ->button(),
            ])
            ->sendToDatabase($approvers);
    }

    /** Notify staf Finance yg CIPTA claim ni (bukan claimant - rujuk restructure, claimant cth.
     * CEO tak perlu/tak login sistem ni, staf Finance yg perlu tahu utk teruskan proses
     * reimbursement atau betulkan item yg ditolak). */
    public function notifyCreator(string $title, string $body): void
    {
        if (! $this->createdBy) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->when($this->status === self::STATUS_APPROVED, fn (Notification $n) => $n->success())
            ->when($this->status === self::STATUS_REJECTED, fn (Notification $n) => $n->danger())
            ->sendToDatabase($this->createdBy);
    }
}
