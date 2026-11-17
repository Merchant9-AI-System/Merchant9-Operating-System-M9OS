<?php

namespace App\Filament\Resources\BranchMonthlyTargets\Tables;

use App\Models\BranchMonthlyTarget;
use App\Models\HqDirection;
use App\Support\BranchActivityCalculator;
use App\Support\HomeSummary\MonthlyActuals;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Senarai sasaran ikut bulan dan cawangan. Tapisan bulan lalai = bulan semasa. Lajur "Sebenar" ialah jualan JEMiSys
 * bulan itu (bulan semasa: sehingga semalam; bulan lalu: penuh) - satu query berkumpul setiap bulan, dicache, dan "-"
 * bila JEMiSys tak dapat dicapai (senarai tetap berfungsi).
 */
class BranchMonthlyTargetsTable
{
    /** Kunci memo per-permintaan (request attributes) - jualan setiap bulan diambil sekali walau banyak baris. */
    private const MEMO_KEY = 'branch_monthly_targets.actuals';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByDesc('month')->orderBy('store_code'))
            ->columns([
                TextColumn::make('month')
                    ->label('Bulan')
                    ->formatStateUsing(fn (Carbon $state): string => $state->locale('ms')->translatedFormat('F Y'))
                    ->sortable(),
                TextColumn::make('store_code')->label('Cawangan')->badge()->searchable()->sortable(),
                TextColumn::make('target_rm')->label('Sasaran RM')->money('MYR')->sortable(),
                TextColumn::make('actual_rm')
                    ->label('Sebenar RM')
                    ->state(fn (BranchMonthlyTarget $record): ?string => self::actual($record, 'rm') === null ? null : 'RM '.number_format(self::actual($record, 'rm'), 2))
                    ->placeholder('-'),
                TextColumn::make('pct_rm')
                    ->label('% RM')
                    ->state(fn (BranchMonthlyTarget $record): ?string => self::pct($record, 'rm'))
                    ->badge()
                    ->color(fn (?string $state): string => self::pctColor($state))
                    ->placeholder('-'),
                TextColumn::make('target_grams')->label('Sasaran gram')->numeric(2)->sortable(),
                TextColumn::make('actual_grams')
                    ->label('Sebenar gram')
                    ->state(fn (BranchMonthlyTarget $record): ?string => self::actual($record, 'grams') === null ? null : number_format(self::actual($record, 'grams'), 2))
                    ->placeholder('-'),
                TextColumn::make('pct_grams')
                    ->label('% gram')
                    ->state(fn (BranchMonthlyTarget $record): ?string => self::pct($record, 'grams'))
                    ->badge()
                    ->color(fn (?string $state): string => self::pctColor($state))
                    ->placeholder('-'),
                TextColumn::make('source')
                    ->label('Ditetapkan')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === BranchMonthlyTarget::SOURCE_BRANCH ? 'Cawangan' : 'HQ')
                    ->color(fn (string $state): string => $state === BranchMonthlyTarget::SOURCE_BRANCH ? 'warning' : 'info')
                    ->description(fn (BranchMonthlyTarget $record): string => $record->set_by_name),
                TextColumn::make('remark')->label('Catatan')->limit(40)->tooltip(fn (BranchMonthlyTarget $record): ?string => $record->remark)->placeholder('-'),
                TextColumn::make('updated_at')->label('Dikemas kini')->dateTime('d/m/Y H:i', 'Asia/Kuala_Lumpur')->sortable(),
            ])
            ->filters([
                SelectFilter::make('month')
                    ->label('Bulan')
                    ->options(fn (): array => BranchMonthlyTarget::monthOptions())
                    ->default(fn (): string => BranchMonthlyTarget::currentMonth()->toDateString())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereDate('month', $data['value']) : $query),
                SelectFilter::make('store_code')
                    ->label('Cawangan')
                    ->options(fn (): array => collect(BranchActivityCalculator::branches())
                        ->mapWithKeys(fn (string $code): array => [BranchMonthlyTarget::normalizeStore($code) => BranchMonthlyTarget::normalizeStore($code)])
                        ->all()),
            ])
            ->headerActions([
                CreateAction::make()->label('Tetapkan sasaran'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalHeading('Padam sasaran ini?'),
            ])
            ->emptyStateHeading('Tiada sasaran untuk tapisan ini')
            ->emptyStateDescription('Tekan "Tetapkan sasaran" atau tukar tapisan bulan/cawangan.');
    }

    /** Jualan sebenar bulan itu bagi cawangan rekod, atau null (tiada data / JEMiSys gagal). */
    private static function actual(BranchMonthlyTarget $record, string $key): ?float
    {
        $monthKey = $record->month->toDateString();
        $memo = request()->attributes->get(self::MEMO_KEY, []);

        if (! array_key_exists($monthKey, $memo)) {
            $month = $record->month->copy()->startOfMonth();
            // Bulan semasa/depan: sehingga semalam; bulan lalu: sehingga hujung bulan (MonthlyActuals menghadkannya).
            $through = BranchMonthlyTarget::currentMonth()->lte($month)
                ? HqDirection::today()->subDay()
                : $month->copy()->endOfMonth();

            $memo[$monthKey] = $month->gt(BranchMonthlyTarget::currentMonth())
                ? []
                : app(MonthlyActuals::class)->forMonth($month, $through);

            request()->attributes->set(self::MEMO_KEY, $memo);
        }

        $actuals = $memo[$monthKey];

        if ($actuals === null) {
            return null;
        }

        return (float) ($actuals[BranchMonthlyTarget::normalizeStore($record->store_code)][$key] ?? 0.0);
    }

    private static function pct(BranchMonthlyTarget $record, string $key): ?string
    {
        $target = (float) ($key === 'rm' ? $record->target_rm : $record->target_grams);
        $actual = self::actual($record, $key);

        return $actual === null || $target <= 0 ? null : number_format($actual / $target * 100, 1).'%';
    }

    private static function pctColor(?string $state): string
    {
        $value = $state === null ? null : (float) $state;

        return match (true) {
            $value === null => 'gray',
            $value >= 100 => 'success',
            $value >= 70 => 'warning',
            default => 'danger',
        };
    }
}
