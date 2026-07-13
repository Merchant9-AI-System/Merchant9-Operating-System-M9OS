<?php

namespace App\Filament\Resources\InventoryPieces\Tables;

use App\Filament\Exports\InventoryPieceExporter;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryPiecesTable
{
    /** Bucket umur - sama definisi seperti PHASE1_FILAMENT_PLAN.md §4 (W2 Aging Chart). */
    private const AGE_BUCKETS = [
        '0-3' => '0-3 bulan',
        '3-6' => '3-6 bulan',
        '6-12' => '6-12 bulan',
        '12+' => '>12 bulan (Dead Stock)',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->groups([
                Group::make('StoreCode')->label('Cawangan')->collapsible(),
                Group::make('category.Description')->label('Kategori')->collapsible(),
            ])
            ->columns([
                TextColumn::make('InternalCode')
                    ->label('Kod Design')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('Description')
                    ->label('Jenis Item')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('category.Description')
                    ->label('Kategori')
                    ->badge()
                    ->sortable(),
                TextColumn::make('vendor.Description')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('StoreCode')
                    ->label('Cawangan')
                    ->badge()
                    ->sortable(),
                TextColumn::make('ClassCode')
                    ->label('Purity')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('JewelSize')
                    ->label('Saiz')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('GoldWeight')
                    ->label('Berat (g)')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('TotalCost')
                    ->label('Kos (RM)')
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('PurchDate')
                    ->label('Tarikh Beli')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('age_days')
                    ->label('Umur (hari)')
                    ->state(fn(InventoryPiece $record) => $record->age_days)
                    ->badge()
                    ->color(fn(?int $state) => match (true) {
                        $state === null => 'gray',
                        $state > 365 => 'danger',
                        $state > 180 => 'warning',
                        default => 'success',
                    })
                    ->sortable(query: fn(Builder $q, string $direction) => $q->orderBy('PurchDate', $direction === 'asc' ? 'desc' : 'asc')),
            ])
            ->filters([
                SelectFilter::make('StoreCode')
                    ->label('Cawangan')
                    ->options(fn() => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode')),

                SelectFilter::make('CategoryCode')
                    ->label('Kategori')
                    ->options(fn() => Category::where('CategoryCode', '!=', '')
                        ->orderBy('Description')
                        ->get()
                        ->mapWithKeys(fn($c) => [$c->CategoryCode => $c->Description ?? $c->CategoryCode])),

                SelectFilter::make('VendorCode')
                    ->label('Supplier')
                    ->searchable()
                    ->options(fn() => Vendor::where('VendorCode', '!=', '.')
                        ->get()
                        ->mapWithKeys(fn($v) => [$v->VendorCode => $v->Description ?? $v->VendorCode])
                        ->sort()),

                SelectFilter::make('ClassCode')
                    ->label('Purity')
                    ->options(fn() => InventoryPiece::query()->onHand()
                        ->whereNotNull('ClassCode')->distinct()
                        ->orderBy('ClassCode')->pluck('ClassCode', 'ClassCode')),

                SelectFilter::make('age_bucket')
                    ->label('Umur Stok')
                    ->options(self::AGE_BUCKETS)
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }
                        $today = now();
                        return match ($value) {
                            '0-3' => $query->where('PurchDate', '>=', $today->copy()->subDays(90)),
                            '3-6' => $query->whereBetween('PurchDate', [$today->copy()->subDays(180), $today->copy()->subDays(90)]),
                            '6-12' => $query->whereBetween('PurchDate', [$today->copy()->subDays(365), $today->copy()->subDays(180)]),
                            '12+' => $query->where('PurchDate', '<', $today->copy()->subDays(365)),
                            default => $query,
                        };
                    }),

                Filter::make('cost_range')
                    ->label('Julat Kos (RM)')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('cost_min')->label('Min')->numeric(),
                        \Filament\Forms\Components\TextInput::make('cost_max')->label('Maks')->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['cost_min'] ?? null, fn($q, $v) => $q->where('TotalCost', '>=', $v))
                            ->when($data['cost_max'] ?? null, fn($q, $v) => $q->where('TotalCost', '<=', $v));
                    }),

                Filter::make('weight_range')
                    ->label('Julat Berat Emas (g)')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('weight_min')->label('Min')->numeric(),
                        \Filament\Forms\Components\TextInput::make('weight_max')->label('Maks')->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['weight_min'] ?? null, fn($q, $v) => $q->where('GoldWeight', '>=', $v))
                            ->when($data['weight_max'] ?? null, fn($q, $v) => $q->where('GoldWeight', '<=', $v));
                    }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make()->slideOver(),
            ])
            ->toolbarActions([
                ExportAction::make()->label('Export')->icon(Heroicon::ArrowDownTray)->exporter(InventoryPieceExporter::class),
            ])
            ->defaultSort('PurchDate', 'desc')
            ->searchPlaceholder('Cari design, jenis item...');
    }
}
