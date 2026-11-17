<?php

namespace App\Models;

use Database\Factories\BranchMonthlyTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sasaran jualan bulanan satu cawangan (M07) dalam RM dan gram. SATU rekod bagi setiap (cawangan, bulan).
 *
 * SEMUA penulisan melalui record() - Filament (HQ) dan /home (ketua cawangan) - supaya setiap perubahan sentiasa
 * direkod dalam revisions (nilai lama -> baharu, catatan, siapa). Ketua cawangan boleh menetapkan jika belum ada
 * dan mengemas kini jika sudah ada (catatan WAJIB - dikuatkuasakan di BranchTargetController); HQ ikut kebenaran
 * Shield pada BranchMonthlyTargetResource.
 */
class BranchMonthlyTarget extends Model
{
    /** @use HasFactory<BranchMonthlyTargetFactory> */
    use HasFactory;

    public const SOURCE_HQ = 'hq';

    public const SOURCE_BRANCH = 'branch';

    protected $fillable = ['store_code', 'month', 'target_rm', 'target_grams', 'remark', 'source', 'set_by_id', 'set_by_name'];

    protected $casts = [
        'month' => 'date',
        'target_rm' => 'decimal:2',
        'target_grams' => 'decimal:2',
    ];

    public function revisions(): HasMany
    {
        return $this->hasMany(BranchMonthlyTargetRevision::class)->latest('id');
    }

    /** Hari pertama bulan semasa (waktu Malaysia). */
    public static function currentMonth(): Carbon
    {
        return HqDirection::today()->startOfMonth();
    }

    public static function normalizeStore(string $store): string
    {
        return strtoupper(trim($store));
    }

    /** Cari sasaran satu cawangan bagi satu bulan (mana-mana hari dalam bulan itu). */
    public static function findFor(string $store, Carbon $month): ?self
    {
        return static::query()
            ->where('store_code', self::normalizeStore($store))
            ->whereDate('month', $month->copy()->startOfMonth()->toDateString())
            ->first();
    }

    /**
     * Tetapkan atau kemas kini sasaran dan rekod semakan. Tiada semakan dicipta jika nilai sama dan tiada catatan
     * baharu (elak bunyi bising).
     */
    public static function record(string $store, Carbon $month, float $rm, float $grams, ?string $remark, User $by): self
    {
        $store = self::normalizeStore($store);
        $monthStart = $month->copy()->startOfMonth();
        $source = $by->hasRole('leader_branch') ? self::SOURCE_BRANCH : self::SOURCE_HQ;
        $remark = filled($remark) ? trim((string) $remark) : null;

        return DB::transaction(function () use ($store, $monthStart, $rm, $grams, $remark, $by, $source) {
            $target = static::query()->where('store_code', $store)->whereDate('month', $monthStart->toDateString())->lockForUpdate()->first();

            $oldRm = $target ? (float) $target->target_rm : null;
            $oldGrams = $target ? (float) $target->target_grams : null;
            $unchanged = $target !== null && round($oldRm, 2) === round($rm, 2) && round($oldGrams, 2) === round($grams, 2);

            $attributes = [
                'target_rm' => round($rm, 2),
                'target_grams' => round($grams, 2),
                'remark' => $remark,
                'source' => $source,
                'set_by_id' => $by->id,
                'set_by_name' => $by->name,
            ];

            if ($target === null) {
                $target = static::create($attributes + ['store_code' => $store, 'month' => $monthStart->toDateString()]);
            } elseif (! $unchanged || $remark !== null) {
                $target->update($attributes);
            } else {
                return $target;
            }

            $target->revisions()->create([
                'old_rm' => $oldRm,
                'old_grams' => $oldGrams,
                'new_rm' => round($rm, 2),
                'new_grams' => round($grams, 2),
                'remark' => $remark,
                'source' => $source,
                'changed_by_id' => $by->id,
                'changed_by_name' => $by->name,
            ]);

            return $target;
        });
    }

    /**
     * Pilihan bulan utk borang (3 bulan lalu hingga 12 bulan depan): 'Y-m-01' => 'November 2026'.
     *
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        $options = [];
        $start = self::currentMonth()->subMonths(3);

        for ($i = 0; $i <= 15; $i++) {
            $month = $start->copy()->addMonths($i);
            $options[$month->toDateString()] = $month->locale('ms')->translatedFormat('F Y');
        }

        return $options;
    }
}
