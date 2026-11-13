<?php

namespace App\Support\HomeSummary;

use App\Filament\Pages\BranchFocus;
use App\Filament\Pages\StockoutReorder;
use App\Filament\Pages\StockRearrangementRecommendation;
use App\Filament\Pages\SupplierPerformance;
use App\Models\User;
use App\Support\CeoActionCentreCalculator;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;

/**
 * Amaran utama CEO (CEO Action Centre) - setiap amaran dipaut ke halaman Filament yang berkaitan (ikut kunci jenis
 * amaran; amaran tanpa halaman khusus atau dari cache lama tanpa kunci tidak berpaut).
 */
class CeoAlertsSection extends CeoSectionBase
{
    public const LIMIT = 3;

    /** Kunci jenis amaran => kelas halaman Filament. */
    private const PAGES = [
        'dead_stock' => Dashboard::class,
        'stockout_best_seller' => StockoutReorder::class,
        'rearrange' => StockRearrangementRecommendation::class,
        'branch_imbalance' => BranchFocus::class,
        'negative_margin_supplier' => SupplierPerformance::class,
    ];

    public function key(): string
    {
        return 'ceo_alerts';
    }

    public function title(): string
    {
        return 'Perlu Perhatian';
    }

    public function build(User $user, Carbon $date): array
    {
        try {
            $list = CeoActionCentreCalculator::alerts()
                ->take(self::LIMIT)
                ->map(fn (array $a) => [
                    'tag' => $a['priority'],
                    'text' => $a['issue'],
                    'tone' => match ($a['priority']) {
                        CeoActionCentreCalculator::HIGH => 'bad',
                        CeoActionCentreCalculator::MEDIUM => 'warn',
                        default => 'default',
                    },
                    'href' => self::urlFor($a['key'] ?? null),
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return ['items' => [self::unavailable('alerts', 'Amaran utama')]];
        }

        return ['items' => [], 'list' => $list];
    }

    public static function urlFor(?string $alertKey): ?string
    {
        $page = $alertKey === null ? null : (self::PAGES[$alertKey] ?? null);

        if ($page === null) {
            return null;
        }

        try {
            return $page::getUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}
