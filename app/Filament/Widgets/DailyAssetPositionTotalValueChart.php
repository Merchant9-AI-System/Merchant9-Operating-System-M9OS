<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Support\RawJs;
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
                // Dataset PEMBAWA tooltip: ada titik pada SETIAP tarikh (termasuk hari tanpa baki bank/cash yg tiada titik
                // pada dua garis di bawah), supaya hover sentiasa papar butiran harian. Tak kelihatan (tiada garis/titik,
                // paksi tersembunyi 'yHidden' supaya tak menjejas skala RM) & tiada dlm legend (rujuk getOptions()).
                [
                    'label' => 'Butiran harian',
                    'data' => array_fill(0, $trend->count(), 0.5),
                    'yAxisID' => 'yHidden',
                    'showLine' => false,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 0,
                    'borderColor' => 'transparent',
                    'backgroundColor' => 'transparent',
                    // Dibaca oleh callback tooltip - kunci tak dikenali Chart.js diabaikan.
                    'extras' => $trend->map(fn (array $r) => [
                        'date' => $r['entry_date']->format('d/m/Y'),
                        'net_weight' => $r['net_weight'],
                        'total_cash' => $r['total_cash'],
                        'market_price' => $r['market_price'],
                    ])->all(),
                ],
                [
                    'label' => 'Net Weight x RM'.number_format($price, 2).' (tetap) + Total Cash',
                    'tooltipLabel' => 'Nilai harga tetap (RM'.number_format($price, 2).'/g)',
                    // Eksplisit: tanpa ini Chart.js ikat ke skala 'y' PERTAMA dlm options.scales = 'yHidden' (ditakrif lebih awal).
                    'yAxisID' => 'y',
                    'data' => $trend->pluck('total_value')->all(),
                    'borderColor' => '#1E3A8A',
                    'backgroundColor' => '#1E3A8A',
                    'borderWidth' => 3,
                    'spanGaps' => true,
                ],
                [
                    'label' => 'Net Weight x harga sebenar harian + Total Cash',
                    'tooltipLabel' => 'Nilai harga sebenar',
                    // Eksplisit: tanpa ini Chart.js ikat ke skala 'y' PERTAMA dlm options.scales = 'yHidden' (ditakrif lebih awal).
                    'yAxisID' => 'y',
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

    /**
     * - Segmen garisan yg MENYAMBUNG jurang (salah satu hujung tiada titik = rekod tanpa baki bank/cash dikeyin)
     *   dilukis putus-putus; segmen antara dua rekod lengkap kekal penuh. Chart.js: options.datasets.line.segment
     *   (spanGaps true pada dataset).
     * - Tooltip (mode 'index', tak perlu tepat di titik): tajuk = tarikh penuh, kemudian Net weight (g), Total Cash (RM),
     *   harga sebenar (RM/g) & nilai setiap garis. Hari tanpa cash dipapar "belum dikeyin" (bukan RM0).
     * - Legend menyembunyikan dataset pembawa tooltip (index 0).
     * Callback JS wajib melalui RawJs (Filament hanya benarkan RawJs utk keseluruhan options).
     */
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            interaction: { mode: 'index', intersect: false },
            datasets: {
                line: {
                    segment: {
                        borderDash: (ctx) => (ctx.p0.skip || ctx.p1.skip ? [6, 6] : undefined),
                    },
                },
            },
            scales: {
                yHidden: { display: false, min: 0, max: 1, grid: { display: false } },
            },
            plugins: {
                legend: {
                    labels: { filter: (item) => item.datasetIndex !== 0 },
                },
                tooltip: {
                    // Chart.js lukis latar putih (multiKeyBackground) di belakang petak warna - telus supaya petak dataset
                    // pembawa (baris butiran) tak kelihatan.
                    multiKeyBackground: 'rgba(0, 0, 0, 0)',
                    callbacks: {
                        title: (items) => {
                            const extras = items[0]?.chart.data.datasets[0]?.extras?.[items[0].dataIndex];

                            return extras?.date ?? items[0]?.label;
                        },
                        label: (ctx) => {
                            const money = (value) => 'RM' + Number(value).toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2,
                            });

                            if (ctx.datasetIndex === 0) {
                                const e = ctx.dataset.extras?.[ctx.dataIndex];

                                if (!e) {
                                    return '';
                                }

                                return [
                                    'Net weight: ' + Number(e.net_weight).toLocaleString('en-US', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2,
                                    }) + ' g',
                                    e.total_cash === null
                                        ? 'Total Cash: belum dikeyin (garisan disambung)'
                                        : 'Total Cash: ' + money(e.total_cash),
                                    'Harga sebenar: ' + (e.market_price === null ? 'tiada' : money(e.market_price) + '/g'),
                                ];
                            }

                            return (ctx.dataset.tooltipLabel ?? ctx.dataset.label) + ': ' + money(ctx.parsed.y);
                        },
                        labelColor: (ctx) => {
                            const transparent = 'rgba(0, 0, 0, 0)';
                            const hidden = ctx.datasetIndex === 0;

                            return {
                                borderColor: hidden ? transparent : ctx.dataset.borderColor,
                                backgroundColor: hidden ? transparent : ctx.dataset.backgroundColor,
                                borderWidth: 2,
                                borderRadius: 2,
                            };
                        },
                    },
                },
            },
        }
        JS);
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
