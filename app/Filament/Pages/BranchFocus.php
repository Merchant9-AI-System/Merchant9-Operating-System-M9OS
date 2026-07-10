<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\Category;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Support\BranchFocusCalculator;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Cawangan mana perlu focus pada kategori mana - 100% drpd data JEMiSys sebenar
 * (rujuk BranchFocusCalculator). TIADA pergantungan PO/GRN/data manual.
 */
class BranchFocus extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.branch-focus';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Branch Focus';

    protected static string|\UnitEnum|null $navigationGroup = 'Analisis JEMiSys';

    protected static ?int $navigationSort = 4;

    public function getSubheading(): ?string
    {
        return 'Cawangan mana perlu fokus (beli/jual) pada kategori mana - dikira 100% drpd data JEMiSys sebenar.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage) {
                // ->records() TIDAK auto-paginate spt ->query() - Filament hantar page/
                // recordsPerPage terus ke closure ni, kena slice & bungkus jadi
                // LengthAwarePaginator sendiri (rujuk Filament\Tables\Concerns\HasRecords).
                $all = BranchFocusCalculator::focus()
                    ->map(fn ($r, $i) => $r + ['InventoryCode' => 'bf_'.$i]);

                $page = (int) $page;
                $recordsPerPage = (int) $recordsPerPage;

                return new LengthAwarePaginator(
                    InventoryPiece::hydrate($all->forPage($page, $recordsPerPage)->values()->all()),
                    $all->count(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('store_code')->label('Cawangan')->badge()->sortable(),
                TextColumn::make('category_name')->label('Kategori')->searchable()->sortable(),
                TextColumn::make('current_stock')->label('Stok Semasa')->numeric()->sortable(),
                TextColumn::make('target_stock')->label('Stok Optimum')->numeric()->sortable(),
                TextColumn::make('gap')->label('Gap')->numeric()->sortable(),
                TextColumn::make('sell_through_rate')->label('% Terjual')
                    ->formatStateUsing(fn ($state) => number_format($state * 100, 1).'%'),
                TextColumn::make('velocity_per_month')->label('Jualan/Bulan')->numeric(2),
                TextColumn::make('focus_area')->label('Cadangan Fokus')->badge()
                    ->color(fn ($state) => match ($state) {
                        'Understock - Fokus Beli' => 'danger',
                        'Overstock - Fokus Jual/Promosi' => 'warning',
                        'Seimbang' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('store_code')->label('Cawangan')
                    ->options(fn () => Store::whereNotIn('StoreCode', ['WEB', 'web'])->orderBy('StoreCode')->pluck('StoreCode', 'StoreCode')),
                SelectFilter::make('category_code')->label('Kategori')
                    ->options(fn () => Category::where('CategoryCode', '!=', '')->pluck('Description', 'CategoryCode')),
                SelectFilter::make('focus_area')->label('Cadangan Fokus')->options([
                    'Understock - Fokus Beli' => 'Understock - Fokus Beli',
                    'Overstock - Fokus Jual/Promosi' => 'Overstock - Fokus Jual/Promosi',
                    'Seimbang' => 'Seimbang',
                    'Data Tak Cukup' => 'Data Tak Cukup',
                ]),
            ])
            ->defaultSort('gap', 'desc')
            ->paginated([25, 50, 100]);
    }
}
