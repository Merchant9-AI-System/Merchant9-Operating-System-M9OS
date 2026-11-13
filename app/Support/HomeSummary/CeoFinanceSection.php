<?php

namespace App\Support\HomeSummary;

use App\Models\User;
use App\Support\DailyAssetPositionCalculator;
use App\Support\PhysicalGoldReconciliationCalculator;
use App\Support\PhysicalGoldReportCalculator;
use App\Support\UsedGoldBalanceProvider;
use Illuminate\Support\Carbon;

/**
 * Kewangan & stok emas utk CEO: Total Asset Value, cash, cadangan lock ACE (formula hedge sahaja buat masa ini),
 * hutang supplier vs Used Gold tersedia, Physical vs Book dan baki Used Gold.
 * Rekod cash/aset dikeyin accountant (bukan harian automatik) - tarikh rekod sentiasa dipaparkan.
 */
class CeoFinanceSection extends CeoSectionBase
{
    /** Rekod accountant lebih lama dari ini (hari sebelum "semalam") dianggap lapuk. */
    private const STALE_AFTER_DAYS = 2;

    public function key(): string
    {
        return 'ceo_finance';
    }

    public function title(): string
    {
        return 'Kewangan & Stok Emas';
    }

    public function build(User $user, Carbon $date): array
    {
        $entry = self::latestCashEntry();

        return ['items' => self::markHero([
            self::safely('asset_value', 'Total Asset Value', fn () => self::assetValueItem()),
            self::safely('available_cash', 'Available Cash', fn () => self::availableCashItem($entry, $date)),
            self::safely('cash_for_gb', 'Cash For GB', fn () => self::cashForGbItem($entry, $date)),
            self::safely('lock_ace', 'Lock ACE (cadangan)', fn () => $this->lockAceItem($entry, $date)),
            self::safely('hutang_used_gold', 'Hutang supplier vs Used Gold', fn () => self::hutangUsedGoldItem()),
            self::safely('physical_vs_book', 'Physical vs Book', fn () => self::physicalVsBookItem()),
            self::safely('used_gold', 'Used Gold (closing bulan ini)', fn () => self::usedGoldItem()),
        ])];
    }

    /**
     * Rekod Daily Asset Position terkini yang ada baki bank/cash dikeyin (hari accountant hanya isi emas diabaikan).
     *
     * @return array<string, mixed>|null
     */
    private static function latestCashEntry(): ?array
    {
        try {
            return DailyAssetPositionCalculator::trend(14)->filter(fn (array $r) => $r['cash_recorded'])->last();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @param  array<string, mixed>  $entry */
    private static function recordHint(array $entry, Carbon $date): string
    {
        $stale = $entry['entry_date']->lt($date->copy()->subDays(self::STALE_AFTER_DAYS)->startOfDay());

        return 'Rekod accountant '.$entry['entry_date']->format('d/m').($stale ? ' (lapuk)' : '');
    }

    /** @param  array<string, mixed>  $entry */
    private static function isStale(array $entry, Carbon $date): bool
    {
        return $entry['entry_date']->lt($date->copy()->subDays(self::STALE_AFTER_DAYS)->startOfDay());
    }

    /** @return array<string, mixed> */
    private static function assetValueItem(): array
    {
        $latest = DailyAssetPositionCalculator::valueTrend(7, withMarketPrice: true)
            ->filter(fn (array $r) => $r['total_value'] !== null)
            ->last();

        if ($latest === null) {
            return self::unavailable('asset_value', 'Total Asset Value', 'Tiada rekod dgn baki bank/cash dlm 7 hari');
        }

        $market = $latest['total_value_market'] !== null
            ? ' · harga sebenar RM'.number_format($latest['total_value_market'] / 1_000_000, 2).'j'
            : '';

        return [
            'key' => 'asset_value',
            'label' => 'Total Asset Value',
            'value' => 'RM'.number_format($latest['total_value'] / 1_000_000, 2).'j',
            'hint' => 'Harga emas tetap'.$market.' · rekod '.$latest['entry_date']->format('d/m'),
            'tone' => 'default',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $entry
     * @return array<string, mixed>
     */
    private static function availableCashItem(?array $entry, Carbon $date): array
    {
        if ($entry === null) {
            return self::unavailable('available_cash', 'Available Cash', 'Tiada rekod dgn baki bank/cash dlm 14 hari');
        }

        return [
            'key' => 'available_cash',
            'label' => 'Available Cash',
            'value' => 'RM'.number_format((float) $entry['available_cash'], 2),
            'hint' => self::recordHint($entry, $date),
            'tone' => self::isStale($entry, $date) ? 'warn' : 'default',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $entry
     * @return array<string, mixed>
     */
    private static function cashForGbItem(?array $entry, Carbon $date): array
    {
        if ($entry === null) {
            return self::unavailable('cash_for_gb', 'Cash For GB', 'Tiada rekod dgn baki bank/cash dlm 14 hari');
        }

        $value = (float) $entry['cash_for_gb'];

        return [
            'key' => 'cash_for_gb',
            'label' => 'Cash For GB',
            'value' => ($value < 0 ? '-' : '').'RM'.number_format(abs($value), 2),
            'hint' => 'Available tolak rizab · '.self::recordHint($entry, $date),
            'tone' => $value < 0 ? 'bad' : (self::isStale($entry, $date) ? 'warn' : 'default'),
        ];
    }

    /**
     * Cadangan lock ACE = hedge semalam (formula Finance). Angka Kairul & logik +10% belum ada, jadi kad ini
     * HANYA formula - dinyatakan jelas. Nilai RM = hedge x kadar beli 999 semalam (anggaran, bukan harga ACE).
     *
     * @param  array<string, mixed>|null  $entry
     * @return array<string, mixed>
     */
    private function lockAceItem(?array $entry, Carbon $date): array
    {
        $report = $this->snapshot->report($date);

        if ($report === null) {
            return self::unavailable('lock_ace', 'Lock ACE (cadangan)');
        }

        $hedge = (float) $report['hedge_pure_weight'];
        $note = 'Formula hedge sahaja - angka Kairul & +10% menyusul';

        if ($hedge <= 0) {
            return [
                'key' => 'lock_ace',
                'label' => 'Lock ACE (cadangan)',
                'value' => 'Tiada lock diperlukan',
                'hint' => 'Hedge semalam '.number_format($hedge, 2).' g · '.$note,
                'tone' => 'default',
            ];
        }

        $price = DailyAssetPositionCalculator::marketPriceByDate([$date])[$date->toDateString()] ?? null;
        $parts = [];
        $tone = 'default';

        if ($price !== null) {
            $cost = $hedge * $price;
            $parts[] = '≈ RM'.number_format($cost, 0).' @ RM'.number_format($price, 2).'/g (kadar beli 999, anggaran)';

            if ($entry !== null) {
                $cashForGb = (float) $entry['cash_for_gb'];
                $enough = $cashForGb >= $cost;
                $parts[] = $enough
                    ? 'Cash For GB RM'.number_format($cashForGb, 0).' cukup'
                    : 'Cash For GB RM'.number_format($cashForGb, 0).' kurang RM'.number_format($cost - $cashForGb, 0);
                $tone = $enough ? 'good' : 'warn';
            }
        } else {
            $parts[] = 'Harga emas tak tersedia utk anggaran RM';
        }

        $parts[] = $note;

        return [
            'key' => 'lock_ace',
            'label' => 'Lock ACE (cadangan)',
            'value' => number_format($hedge, 2).' g tulen',
            'hint' => implode(' · ', $parts),
            'tone' => $tone,
        ];
    }

    /** @return array<string, mixed> */
    private static function hutangUsedGoldItem(): array
    {
        $trend = DailyAssetPositionCalculator::usedGoldVsHutangTrend(30);
        $hutang = $trend->filter(fn (array $r) => $r['supplier_hutang'] !== null)->last();
        $used = $trend->filter(fn (array $r) => $r['used_gold'] !== null)->last();

        if ($hutang === null || $used === null) {
            return self::unavailable('hutang_used_gold', 'Hutang supplier vs Used Gold', 'Perlukan rekod hutang & Physical Gold Report diluluskan');
        }

        $difference = (float) $used['used_gold'] - (float) $hutang['supplier_hutang'];

        return [
            'key' => 'hutang_used_gold',
            'label' => 'Hutang supplier vs Used Gold',
            'value' => number_format((float) $hutang['supplier_hutang'], 2).' g hutang',
            'hint' => 'Used Gold tersedia '.number_format((float) $used['used_gold'], 2).' g (tanpa YS/KIV) · '
                .($difference >= 0 ? 'cukup, lebihan '.number_format($difference, 2).' g' : 'kurang '.number_format(abs($difference), 2).' g')
                .' · hutang '.date('d/m', strtotime($hutang['date'])).', used gold '.date('d/m', strtotime($used['date'])),
            'tone' => $difference >= 0 ? 'good' : 'bad',
        ];
    }

    /** @return array<string, mixed> */
    private static function physicalVsBookItem(): array
    {
        $summary = PhysicalGoldReconciliationCalculator::latestSummary();

        if ($summary === null) {
            return self::unavailable('physical_vs_book', 'Physical vs Book', 'Belum ada Physical Gold Report diluluskan');
        }

        $reportDate = PhysicalGoldReportCalculator::latestApproved()?->report_date?->format('d/m');

        if ($summary['variance'] === null) {
            return [
                'key' => 'physical_vs_book',
                'label' => 'Physical vs Book',
                'value' => 'Tiada rekod book',
                'hint' => 'Physical '.number_format((float) $summary['physical_net_pure_gold'], 2).' g · laporan '.$reportDate,
                'tone' => 'warn',
            ];
        }

        $variance = (float) $summary['variance'];
        $pct = (float) $summary['variance_pct'];

        return [
            'key' => 'physical_vs_book',
            'label' => 'Physical vs Book',
            'value' => ($variance >= 0 ? '+' : '-').number_format(abs($variance), 2).' g ('.($pct >= 0 ? '+' : '-').number_format(abs($pct), 1).'%)',
            'hint' => 'Physical '.number_format((float) $summary['physical_net_pure_gold'], 2).' g · Book '.number_format((float) $summary['book_net_weight'], 2).' g · laporan '.$reportDate,
            'tone' => match ($summary['status']) {
                PhysicalGoldReconciliationCalculator::STATUS_GREEN => 'good',
                PhysicalGoldReconciliationCalculator::STATUS_YELLOW => 'warn',
                PhysicalGoldReconciliationCalculator::STATUS_RED => 'bad',
                default => 'default',
            },
        ];
    }

    /** @return array<string, mixed> */
    private static function usedGoldItem(): array
    {
        $total = UsedGoldBalanceProvider::totalClosing();

        return [
            'key' => 'used_gold',
            'label' => 'Used Gold (closing bulan ini)',
            'value' => $total !== null ? number_format($total, 2).' g' : self::UNAVAILABLE,
            'hint' => 'Sistem kedaiemas',
            'tone' => $total !== null ? 'default' : 'warn',
        ];
    }
}
