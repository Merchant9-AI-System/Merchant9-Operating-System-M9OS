<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

/**
 * Dua garis (RM): (1) Net Weight x harga emas TETAP + Total Cash, (2) Net Weight x harga emas SEBENAR
 * harian + Total Cash. Garis tetap hanya turun bila stok/cash sebenar turun; jurang antara kedua-dua garis
 * ialah kesan pergerakan harga pasaran. Harga tetap: config dashboard.daily_asset_position.
 * gold_reference_price_per_gram; harga sebenar: BuyRate 999 JEMiSys (DailyAssetPositionCalculator::
 * marketPriceByDate()).
 */
class DailyAssetPositionTotalValueChart extends ChartWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Total Asset Value: Harga Tetap vs Harga Sebenar Harian';

    public ?string $filter = 'thirty_days';

    protected function getFilters(): ?array
    {
        return [
            'week' => '1 Minggu',
            'thirty_days' => '30 Hari',
            'ninety_days' => '90 Hari',
            'six_months' => '6 Bulan',
            'year' => '1 Tahun',
        ];
    }

    protected function daysForFilter(): int
    {
        return match ($this->filter) {
            'week' => 7,
            'ninety_days' => 90,
            'six_months' => 182,
            'year' => 365,
            default => 30,
        };
    }

    protected function getData(): array
    {
        $trend = DailyAssetPositionCalculator::valueTrend($this->daysForFilter(), withMarketPrice: true);
        $price = DailyAssetPositionCalculator::goldReferencePrice();

        return [
            'datasets' => [
                [
                    'label' => 'Net Weight x RM'.number_format($price, 2).' (tetap) + Total Cash',
                    'data' => $trend->pluck('total_value')->all(),
                    'borderColor' => '#1E3A8A',
                    'backgroundColor' => '#1E3A8A',
                    'borderWidth' => 3,
                    'spanGaps' => true,
                ],
                [
                    'label' => 'Net Weight x harga sebenar harian + Total Cash',
                    'data' => $trend->pluck('total_value_market')->all(),
                    'borderColor' => '#B45309',
                    'backgroundColor' => '#B45309',
                    'borderWidth' => 3,
                    'spanGaps' => true,
                ],
            ],
            'labels' => $trend->pluck('entry_date')->map(fn ($d) => $d->format('d/m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        $price = DailyAssetPositionCalculator::goldReferencePrice();

        if ($price <= 0) {
            return 'Harga rujukan emas belum diset (DAP_GOLD_REFERENCE_PRICE) - nilai emas tidak dapat dikira.';
        }

        $trend = DailyAssetPositionCalculator::valueTrend($this->daysForFilter(), withMarketPrice: true);

        if ($trend->isEmpty()) {
            return 'Trend tidak tersedia lagi - tiada rekod Book Balance Asset Position dikeyin.';
        }

        $withCash = $trend->filter(fn (array $r) => $r['total_value'] !== null)->values();
        $missingCash = $trend->count() - $withCash->count();

        if ($withCash->isEmpty()) {
            return 'Tiada rekod dgn baki bank/cash dikeyin - Total Asset Value tidak dapat dikira.';
        }

        $latest = $withCash->last();
        $previous = $withCash->count() > 1 ? $withCash->get($withCash->count() - 2) : null;

        $text = 'Total Cash = Ambank + Affin + Cash + Affin FD (kasar). Terkini (harga tetap RM'.number_format($price, 2).'/g): RM'.number_format($latest['total_value'], 2);

        if ($previous) {
            $change = $latest['total_value'] - $previous['total_value'];
            $text .= ' ('.($change >= 0 ? '+' : '-').'RM'.number_format(abs($change), 2).' vs rekod sebelumnya dgn cash)';
        }

        if ($latest['total_value_market'] !== null) {
            $text .= '. Harga sebenar RM'.number_format($latest['market_price'], 2).'/g: RM'.number_format($latest['total_value_market'], 2)
                .' (beza RM'.number_format($latest['total_value_market'] - $latest['total_value'], 2).')';
        } else {
            $text .= '. Harga sebenar harian tidak dapat diambil drpd JEMiSys sekarang';
        }

        $text .= '.';

        if ($missingCash > 0) {
            $text .= " {$missingCash} daripada {$trend->count()} rekod tiada baki bank/cash dikeyin - garisan disambung antara rekod yg ada (bukan sifar).";
        }

        return $text;
    }
}
