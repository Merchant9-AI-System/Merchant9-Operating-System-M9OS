<?php

namespace App\Filament\Resources\DailyAssetPositions\Tables;

use App\Models\DailyAssetPosition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyAssetPositionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entry_date')->label('Tarikh')->date('d/m/Y')->sortable(),
                TextColumn::make('opening_stock_weight')->label('Opening (g)')->numeric(3)->toggleable(),
                TextColumn::make('total_stock_in')->label('In (g)')->numeric(3)->color('success')->toggleable(),
                TextColumn::make('total_stock_out')->label('Out (g)')->numeric(3)->color('danger')->toggleable(),
                TextColumn::make('closing_stock')->label('Closing (g)')->numeric(3)->sortable(),
                TextColumn::make('net_weight')->label('Net Weight (g)')->numeric(3)->sortable(),
                TextColumn::make('available_cash')->label('Available Cash')->money('MYR')->sortable(),
                TextColumn::make('supplier_hutang')->label('Hutang (g)')->numeric(3)->toggleable(),
                TextColumn::make('supplier_overpaid')->label('Overpaid (g)')->numeric(3)->toggleable(),
                IconColumn::make('mismatch')
                    ->label('Mismatch')
                    ->state(fn (DailyAssetPosition $record) => $record->hasAnyMismatch())
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success'),
                TextColumn::make('created_by')->label('Created By')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_by')->label('Updated By')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('month')
                    ->label('Bulan/Tahun')
                    ->schema([
                        Select::make('year')
                            ->label('Tahun')
                            ->options(fn () => DailyAssetPosition::query()
                                ->pluck('entry_date')
                                ->map(fn ($date) => $date->format('Y'))
                                ->unique()
                                ->sortDesc()
                                ->mapWithKeys(fn ($y) => [$y => $y])),
                        Select::make('month')
                            ->label('Bulan')
                            ->options([
                                '01' => 'Januari', '02' => 'Februari', '03' => 'Mac', '04' => 'April',
                                '05' => 'Mei', '06' => 'Jun', '07' => 'Julai', '08' => 'Ogos',
                                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Disember',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['year'] ?? null, fn ($q, $y) => $q->whereYear('entry_date', $y))
                            ->when($data['month'] ?? null, fn ($q, $m) => $q->whereMonth('entry_date', $m));
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['year'] ?? null) && blank($data['month'] ?? null)) {
                            return null;
                        }

                        return 'Bulan: '.($data['month'] ?? '-').'/'.($data['year'] ?? '-');
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('entry_date', 'desc');
    }
}
