<?php

namespace App\Filament\Widgets;

use App\Support\CeoActionCentreCalculator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * CEO Dashboard Phase 1 (A) - senarai amaran keutamaan digabung drpd kalkulator sedia ada
 * (rujuk CeoActionCentreCalculator). Widget baru, TIADA kesan pada widget dashboard lain.
 * Boleh dimatikan bila-bila via .env CEO_ACTION_CENTRE_ENABLED=false (config/dashboard.php).
 */
class CeoActionCentre extends TableWidget
{
    protected static ?string $heading = 'CEO Action Centre';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return (bool) config('dashboard.ceo_features.action_centre', true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => CeoActionCentreCalculator::alerts()->take(8)->all())
            ->columns([
                TextColumn::make('priority')
                    ->label('Priority')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        CeoActionCentreCalculator::HIGH => 'danger',
                        CeoActionCentreCalculator::MEDIUM => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('issue')->label('Issue')->wrap(),
                TextColumn::make('suggested_action')->label('Suggested Action')->wrap(),
                TextColumn::make('estimated_impact')->label('Estimated Impact')->wrap(),
                TextColumn::make('data_source')->label('Data Source')->wrap()->color('gray')->size('xs'),
                TextColumn::make('rule_based')
                    ->label('Asas')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (bool $state) => $state ? 'Rule-based suggestion' : 'Dikira penuh'),
            ])
            ->paginated(false);
    }

    public function getDescription(): ?string
    {
        return '5-8 amaran keutamaan digabung drpd data JEMiSys semasa. Semua andaian/threshold dilabel "rule-based suggestion" - belum dikalibrasi drpd data sejarah.';
    }
}
