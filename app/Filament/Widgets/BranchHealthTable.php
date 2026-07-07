<?php

namespace App\Filament\Widgets;

use App\Support\BranchHealthCalculator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * CEO Dashboard Phase 1 (B) - table baru DI BAWAH chart "Berat Emas vs Ideal Setiap Cawangan"
 * sedia ada (GoldVsIdealByBranch, widget itu TIDAK diubah). Rujuk BranchHealthCalculator.
 * Boleh dimatikan via .env CEO_BRANCH_HEALTH_ENABLED=false (config/dashboard.php).
 */
class BranchHealthTable extends TableWidget
{
    protected static ?string $heading = 'Branch Health';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return (bool) config('dashboard.ceo_features.branch_health', true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Status (Healthy/Watch/Critical) ialah "rule-based suggestion" drpd dead stock % & bilangan best-seller sold out per cawangan.')
            ->records(fn () => BranchHealthCalculator::rows()->all())
            ->columns([
                TextColumn::make('store_code')->label('Branch')->badge(),
                TextColumn::make('gold_weight_kg')->label('Current Gold Weight (kg)')->numeric(2)->sortable(),
                TextColumn::make('inventory_value')->label('Inventory Value')->money('MYR')->sortable(),
                TextColumn::make('dead_stock_pct')->label('Dead Stock %')
                    ->formatStateUsing(fn ($state) => number_format($state, 1).'%')
                    ->sortable(),
                TextColumn::make('stockout_bestseller_count')->label('Sold Out Best Seller')->numeric()->sortable(),
                TextColumn::make('status')->label('Suggested Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        BranchHealthCalculator::CRITICAL => 'danger',
                        BranchHealthCalculator::WATCH => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('suggested_action')->label('Suggested Action')->wrap(),
            ])
            ->paginated(false)
            ->defaultSort('inventory_value', 'desc');
    }
}
