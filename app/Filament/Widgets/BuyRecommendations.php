<?php

namespace App\Filament\Widgets;

use App\Models\Jemisys\InventoryPiece;
use App\Support\OrderRecommendationCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * "Cadangan Beli" - top design yg patut diorder (rujuk OrderRecommendationCalculator, port
 * disahkan 100% padan Python procurement_report.py build_order_recommendation()).
 */
class BuyRecommendations extends TableWidget
{
    // use HasWidgetShield;
    
    protected static ?string $heading = 'Cadangan Beli (Open-to-Buy Terkawal)';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => InventoryPiece::hydrate(
                OrderRecommendationCalculator::recommendations()
                    ->map(fn ($r) => $r + ['InventoryCode' => $r['internal_code']])
                    ->take(10)
                    ->all()
            ))
            ->columns([
                TextColumn::make('internal_code')->label('Design')->weight('bold'),
                TextColumn::make('vendor_name')->label('Supplier'),
                TextColumn::make('recommend_qty')->label('Qty')->numeric()->badge()->color('primary'),
                TextColumn::make('cover_months')->label('Cover')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 1).'b' : '-')
                    ->badge()
                    ->color(fn ($state) => $state === null ? 'gray' : ($state < 0.5 ? 'danger' : ($state < 1.5 ? 'warning' : 'success'))),
            ])
            ->paginated(false);
    }
}
