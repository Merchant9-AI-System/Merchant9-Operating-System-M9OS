<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\InventoryPiece;
use App\Support\StockRearrangementRecommender;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * CEO Dashboard Phase 1 (E) - versi RINGKAS & PAPAR SAHAJA (read-only) drpd cadangan
 * rearrange, berasingan drpd page Rearrange sedia ada (yg ada tindakan tulis "Cipta
 * Transfer" - TIDAK disentuh). Rujuk StockRearrangementRecommender utk rule. Boleh
 * dimatikan via .env CEO_REARRANGEMENT_ENABLED=false (config/dashboard.php).
 */
class StockRearrangementRecommendation extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.stock-rearrangement-recommendation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Cadangan Rearrange (Ringkas)';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return (bool) config('dashboard.ceo_features.rearrangement', true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Rule ringkas: design ada stok di Cawangan A, sold out di Cawangan B -> cadang pindah A ke B. '.
            'Papar sahaja (read-only), tiada transfer dicipta secara automatik. Utk cipta transfer sebenar, guna page Rearrange.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => InventoryPiece::hydrate(
                StockRearrangementRecommender::recommendations()
                    ->map(fn ($r, $i) => $r + ['InventoryCode' => 'sr_'.$i])
                    ->all()
            ))
            ->columns([
                TextColumn::make('from_branch')->label('From Branch')->badge()->color('success'),
                TextColumn::make('to_branch')->label('To Branch')->badge()->color('danger'),
                TextColumn::make('internal_code')->label('Design / SKU')->searchable(),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25),
                TextColumn::make('current_stock')->label('Current Stock')->numeric(),
                TextColumn::make('reason')->label('Reason')->wrap(),
                TextColumn::make('priority')->label('Priority')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        StockRearrangementRecommender::HIGH => 'danger',
                        StockRearrangementRecommender::MEDIUM => 'warning',
                        default => 'gray',
                    }),
            ])
            ->paginated([10, 25, 50, 100])
            ->searchPlaceholder('Cari kod design...');
    }
}
