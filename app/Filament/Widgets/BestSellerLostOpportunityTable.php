<?php

namespace App\Filament\Widgets;

use App\Support\BestSellerLostOpportunityCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * CEO Dashboard Phase 1 (D) - header widget pada page StockoutReorder sedia ada (rujuk
 * StockoutReorder::getHeaderWidgets()). Top 10 design sold out ikut bilangan pernah jual.
 */
class BestSellerLostOpportunityTable extends TableWidget
{
    // use HasWidgetShield;

    protected static ?string $heading = 'Top 10 Sold-Out Designs';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => BestSellerLostOpportunityCalculator::summary()['top10']->all())
            ->columns([
                TextColumn::make('internal_code')->label('Kod Design'),
                TextColumn::make('sold_count')->label('Pernah Terjual')->numeric()->badge()->color('danger'),
                TextColumn::make('description')->label('Jenis Item')->limit(30),
                TextColumn::make('category_name')->label('Kategori')->badge(),
                TextColumn::make('last_sale_date')->label('Jualan Terkini')->date('d/m/Y'),
                TextColumn::make('vendor_name')->label('Supplier')->wrap()->limit(30)->size('xs'),
            ])
            ->paginated(false);
    }
}
