<?php

namespace App\Filament\Widgets;

use App\Models\StockoutReorderCandidate;
use App\Support\BestSellerLostOpportunityCalculator;
use App\Support\ProductImageFetcher;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * CEO Dashboard Phase 1 (D) - header widget pada page StockoutReorder sedia ada (rujuk
 * StockoutReorder::getHeaderWidgets()). Top 10 design sold out ikut bilangan pernah jual.
 *
 * Ada penapis "Julat Tarikh" SENDIRI (BERASINGAN drpd penapis jadual utama StockoutReorder) -
 * widget ni panggil BestSellerLostOpportunityCalculator::summary() terus ikut julat yg dipilih
 * DI WIDGET NI, bukan ikut julat page (arahan eksplisit: tambah dropdown di widget ni jugak).
 * Rujuk BestSellerLostOpportunityStats utk widget stats yg KEKAL ikut julat page (tak diubah).
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
            ->records(function (?array $filters) {
                $range = $filters['range']['value'] ?? StockoutReorderCandidate::RANGE_1_WEEK;

                return BestSellerLostOpportunityCalculator::summary($range)['top10'];
            })
            ->filters([
                SelectFilter::make('range')
                    ->label('Julat Tarikh')
                    ->native()
                    ->default(StockoutReorderCandidate::RANGE_1_WEEK)
                    ->options(StockoutReorderCandidate::RANGE_LABELS),
            ])
            ->columns([
                ImageColumn::make('InternalCodeImage')
                    ->label('Imej')
                    ->state(fn (array $record) => ProductImageFetcher::firstImageUrlFor($record['internal_code']))
                    ->imageHeight(50)
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->url(fn (?string $state): ?string => $state)
                    ->openUrlInNewTab()
                    ->placeholder('No image'),
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
