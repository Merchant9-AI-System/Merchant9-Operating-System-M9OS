<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * "Daily Company Asset Position" - lapisan kawalan/reconciliation harian yg dikeyin accountant
 * (bukan drpd JEMiSys/ERP). Jadual sendiri (connection default/sqlite) - TIADA tulisan ke jemisys,
 * TIADA pelarasan automatik inventori JEMiSys drpd data ni (rujuk App\Support\DailyAssetPositionCalculator
 * utk reconciliation baca-sahaja vs JEMiSys).
 *
 * Medan "computed" (total_stock_in, total_stock_out, net_weight, available_cash) SENTIASA dikira
 * semula di sini (booted() saving()) - tak pernah dipercayai terus drpd input borang, supaya nilai
 * simpanan konsisten dgn formula walau apa pun state borang semasa hantar.
 */
class DailyAssetPosition extends Model
{
    use HasFactory;

    // protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date',
        'opening_stock_weight' => 'decimal:3',
        'new_stock' => 'decimal:3',
        'used_gold' => 'decimal:3',
        'gold_bar' => 'decimal:3',
        'unreceived_bar' => 'decimal:3',
        'loan_received' => 'decimal:3',
        'total_stock_in' => 'decimal:3',
        'sales' => 'decimal:3',
        'payment_to_supplier' => 'decimal:3',
        'stock_out_return' => 'decimal:3',
        'loss_from_melting' => 'decimal:3',
        'loan_out' => 'decimal:3',
        'total_stock_out' => 'decimal:3',
        'closing_stock' => 'decimal:3',
        'supplier_hutang' => 'decimal:3',
        'supplier_overpaid' => 'decimal:3',
        'net_weight' => 'decimal:3',
        'ambank_balance' => 'decimal:2',
        'affin_balance' => 'decimal:2',
        'cash' => 'decimal:2',
        'affin_rm' => 'decimal:2',
        'od_affin' => 'decimal:2',
        'locked_gold_bar' => 'decimal:3',
        'available_cash' => 'decimal:2',
    ];

    /** Toleransi bulat float utk banding mismatch (elak false-positive drpd floating point). */
    private const EPSILON = 0.005;

    protected static function booted(): void
    {
        static::saving(function (DailyAssetPosition $entry) {
            $entry->total_stock_in = $entry->calculateTotalStockIn();
            $entry->total_stock_out = $entry->calculateTotalStockOut();
            $entry->net_weight = $entry->calculateNetWeight();
            $entry->available_cash = $entry->calculateAvailableCash();
        });

        static::created(function (DailyAssetPosition $entry) {
            $entry->writeAudit('created', collect($entry->getAttributes())
                ->mapWithKeys(fn ($value, $key) => [$key => ['old' => null, 'new' => $value]])
                ->all());
        });

        static::updated(function (DailyAssetPosition $entry) {
            $changes = collect($entry->getChanges())
                ->except(['updated_at'])
                ->mapWithKeys(fn ($value, $key) => [$key => ['old' => $entry->getOriginal($key), 'new' => $value]])
                ->all();

            if (! empty($changes)) {
                $entry->writeAudit('updated', $changes);
            }
        });
    }

    protected function writeAudit(string $action, array $changes): void
    {
        $this->audits()->create([
            'action' => $action,
            'actor' => Auth::user()?->name ?? $this->updated_by ?? $this->created_by ?? 'system',
            'changes' => $changes,
        ]);
    }

    public function audits()
    {
        return $this->hasMany(DailyAssetPositionAudit::class)->latest('id');
    }

    public function calculateTotalStockIn(): float
    {
        return round(
            (float) $this->new_stock + (float) $this->used_gold + (float) $this->gold_bar
            + (float) $this->unreceived_bar + (float) $this->loan_received,
            3
        );
    }

    public function calculateTotalStockOut(): float
    {
        return round(
            (float) $this->sales + (float) $this->payment_to_supplier + (float) $this->stock_out_return
            + (float) $this->loss_from_melting + (float) $this->loan_out,
            3
        );
    }

    /** Closing stock ikut formula (utk banding vs closing_stock yg dikeyin accountant - lihat hasClosingStockMismatch()). */
    public function calculateClosingStock(): float
    {
        return round(
            (float) $this->opening_stock_weight + $this->calculateTotalStockIn() - $this->calculateTotalStockOut(),
            3
        );
    }

    public function calculateNetWeight(): float
    {
        return round((float) $this->closing_stock - (float) $this->supplier_hutang + (float) $this->supplier_overpaid, 3);
    }

    public function calculateAvailableCash(): float
    {
        return round(
            (float) $this->ambank_balance + (float) $this->affin_balance + (float) $this->cash
            + (float) $this->affin_rm - (float) $this->od_affin,
            2
        );
    }

    /** Rekod terdekat SEBELUM tarikh ni (bukan semestinya semalam kalendar - toleransi gap). */
    public function previousEntry(): ?self
    {
        return static::where('entry_date', '<', $this->entry_date)
            ->orderByDesc('entry_date')
            ->first();
    }

    public function hasOpeningStockMismatch(): bool
    {
        $previous = $this->previousEntry();
        if (! $previous) {
            return false;
        }

        return abs((float) $this->opening_stock_weight - (float) $previous->closing_stock) > self::EPSILON;
    }

    public function hasClosingStockMismatch(): bool
    {
        return abs((float) $this->closing_stock - $this->calculateClosingStock()) > self::EPSILON;
    }

    public function hasAnyMismatch(): bool
    {
        return $this->hasOpeningStockMismatch() || $this->hasClosingStockMismatch();
    }

    /**
     * Closing stock rekod terdekat SEBELUM $date (utk default/banding opening stock borang).
     * $date=null bermaksud "rekod paling terkini keseluruhan" (utk borang Create tanpa tarikh lagi).
     */
    public static function closingStockBefore(mixed $date): ?float
    {
        $query = static::query()->orderByDesc('entry_date');

        if (filled($date)) {
            $query->where('entry_date', '<', $date);
        }

        $value = $query->value('closing_stock');

        return $value !== null ? (float) $value : null;
    }
}
