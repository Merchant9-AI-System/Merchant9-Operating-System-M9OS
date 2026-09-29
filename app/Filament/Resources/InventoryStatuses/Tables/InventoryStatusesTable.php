<?php

namespace App\Filament\Resources\InventoryStatuses\Tables;

use App\Enums\JemisysInventoryStatus;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\Store;
use App\Support\ProductImageFetcher;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class InventoryStatusesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->groups([
                Group::make('StoreCode')->label('Cawangan')->collapsible(),
                Group::make('Status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn ($record) => JemisysInventoryStatus::labelFor($record->Status))
                    ->collapsible(),
            ])
            ->columns([
                ImageColumn::make('InternalCodeImage')
                    ->label('Imej')
                    ->state(fn ($record) => ProductImageFetcher::firstImageUrlsFor($record->InternalCode))
                    ->circular()
                    ->stacked()
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->url(fn (string $state): string => $state)
                    ->openUrlInNewTab()
                    ->placeholder('No image')
                    ->toggleable(),
                TextColumn::make('InternalCode')
                    ->label('Kod Design')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('Description')
                    ->label('Jenis Item')
                    ->searchable()
                    ->limit(30)
                    ->description(fn ($record) => $record->category?->Description, position: 'above')
                    ->toggleable(),
                TextColumn::make('nickname')
                    ->label('Nama Item')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('StoreCode')
                    ->label('Cawangan')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('Status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => JemisysInventoryStatus::labelFor($state))
                    ->color(fn (?string $state) => JemisysInventoryStatus::colorFor($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('GoldWeight')
                    ->label('Berat (g)')
                    ->numeric(2)
                    ->sortable()
                    ->summarize(Sum::make())
                    ->toggleable(),
                TextColumn::make('PurchDate')
                    ->label('Tarikh Beli')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('SalesDate')
                    ->label('Tarikh Jual')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        SelectConstraint::make('StoreCode')
                            ->label('Cawangan')
                            ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode'))
                            ->multiple(),

                        SelectConstraint::make('Status')
                            ->label('Status')
                            ->options(JemisysInventoryStatus::options())
                            ->multiple(),

                        TextConstraint::make('InternalCode')
                            ->label('Kod Design'),

                        TextConstraint::make('Description')
                            ->label('Jenis Item'),

                        TextConstraint::make('nickname')
                            ->label('Nama Item')
                            ->nullable(),

                        SelectConstraint::make('CategoryCode')
                            ->label('Kategori')
                            ->options(fn () => Category::where('CategoryCode', '!=', '')
                                ->orderBy('Description')
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->CategoryCode => $c->Description ?? $c->CategoryCode]))
                            ->searchable()
                            ->multiple(),

                        NumberConstraint::make('GoldWeight')
                            ->label('Berat (g)'),

                        DateConstraint::make('PurchDate')
                            ->label('Tarikh Beli'),

                        DateConstraint::make('SalesDate')
                            ->label('Tarikh Jual')
                            ->nullable(),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                ViewAction::make()->slideOver(),
            ])
            ->defaultSort('PurchDate', 'desc')
            // Simple (bukan Default) - elak SELECT COUNT(*) tanpa WHERE pd 500K+ baris (InnoDB
            // kena full scan utk count tepat, ambil ~3.2s vs data sebenar cuma ~30ms). Hilang
            // "Showing X of Y" & jump-to-page, tukar jadi Prev/Next je - acceptable utk jadual
            // sebesar ni (filter Cawangan/Status dulu kalau perlu navigasi lebih spesifik).
            ->paginationMode(PaginationMode::Simple)
            ->searchPlaceholder('Cari design, jenis item, & nama item...');
    }
}
