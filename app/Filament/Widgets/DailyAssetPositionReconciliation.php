<?php

namespace App\Filament\Widgets;

use App\Support\DailyAssetPositionCalculator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * CEO Dashboard - status reconciliation JEMiSys vs accountant (rujuk DailyAssetPositionCalculator
 * utk mapping proksi & nota kejujuran data). Papar sahaja - TIADA pelarasan automatik.
 */
class DailyAssetPositionReconciliation extends TableWidget
{
    protected static ?string $heading = 'Jemisys vs Accountant Reconciliation';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return (bool) config('dashboard.ceo_features.daily_asset_position', true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => DailyAssetPositionCalculator::reconciliation() === []
                ? 'Reconciliation belum tersedia - tiada rekod Daily Asset Position dikeyin lagi.'
                : 'JEMiSys tiada snapshot harian - "Closing Stock"/"Branch Stock Total" bandingkan stok SEKARANG vs rekod accountant terkini sahaja.')
            ->records(fn () => collect(DailyAssetPositionCalculator::reconciliation())->values()->all())
            ->columns([
                TextColumn::make('label')->label('Metric'),
                TextColumn::make('jemisys')->label('Jemisys')->numeric(3)->suffix(' g'),
                TextColumn::make('accountant')->label('Accountant')->numeric(3)->suffix(' g'),
                TextColumn::make('diff')->label('Difference')->numeric(3)->suffix(' g'),
                TextColumn::make('diff_pct')->label('% Beza')->formatStateUsing(fn ($state) => number_format($state, 2).'%'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        DailyAssetPositionCalculator::STATUS_GREEN => 'Matched',
                        DailyAssetPositionCalculator::STATUS_YELLOW => 'Small Difference',
                        DailyAssetPositionCalculator::STATUS_RED => 'Major Difference',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        DailyAssetPositionCalculator::STATUS_GREEN => 'success',
                        DailyAssetPositionCalculator::STATUS_YELLOW => 'warning',
                        DailyAssetPositionCalculator::STATUS_RED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->paginated(false);
    }
}
