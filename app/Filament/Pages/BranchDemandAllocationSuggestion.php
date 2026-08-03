<?php

namespace App\Filament\Pages;

use App\Models\BranchDemandRequestLine;
use App\Models\Jemisys\InventoryPiece;
use App\Models\Jemisys\Store;
use App\Models\StockTransfer;
use App\Support\BranchDemandAllocationRecommender;
use App\Support\ProductImageFetcher;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * Module D (Automatic Stock Allocation Suggestion) - senarai demand cawangan yg DAH DILULUSKAN
 * HQ tapi masih ada qty_outstanding, berserta cadangan cawangan donor (rujuk
 * App\Support\BranchDemandAllocationRecommender). "Cipta Transfer" cipta StockTransfer terus,
 * ditaut balik ke BranchDemandRequestLine asal (branch_demand_request_line_id) supaya baris
 * yg sama tak dicadang berulang kali selepas transfer dicipta.
 */
class BranchDemandAllocationSuggestion extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.branch-demand-allocation-suggestion';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Cadangan Penuhan Demand';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 3;

    public function getSubheading(): ?string
    {
        return 'Demand cawangan yg dah diluluskan HQ tapi belum dipenuhi - cadangan cawangan donor terbaik. '.
            'Cipta transfer terus drpd sini utk penuhi permintaan.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage, ?array $filters, ?string $search, ?array $columnSearches, ?string $sortColumn, ?string $sortDirection) {
                $all = BranchDemandAllocationRecommender::recommendations()
                    ->map(fn ($r, $i) => $r + ['InventoryCode' => 'bda_'.$i]);

                if ($fromBranch = $filters['from_branch']['value'] ?? null) {
                    $all = $all->where('from_branch', $fromBranch);
                }

                if ($toBranch = $filters['to_branch']['value'] ?? null) {
                    $all = $all->where('to_branch', $toBranch);
                }

                if (filled($search)) {
                    $needle = mb_strtolower($search);
                    $all = $all->filter(fn ($r) => str_contains(mb_strtolower((string) $r['internal_code']), $needle)
                        || str_contains(mb_strtolower((string) $r['request_number']), $needle));
                }

                if (filled($sortColumn)) {
                    $all = $sortDirection === 'desc' ? $all->sortByDesc($sortColumn) : $all->sortBy($sortColumn);
                }

                $all = $all->values();
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
                ImageColumn::make('InternalCodeImage')
                    ->label('Imej')
                    ->state(fn ($record) => ProductImageFetcher::firstImageUrlFor($record->internal_code))
                    ->imageHeight(50)
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->placeholder('No image'),
                TextColumn::make('request_number')->label('No. Permintaan')->badge()->color('gray'),
                TextColumn::make('from_branch')->label('Daripada')->badge()->color('success'),
                TextColumn::make('to_branch')->label('Ke')->badge()->color('danger'),
                TextColumn::make('internal_code')->label('Design / SKU')->searchable(isIndividual: true),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25),
                TextColumn::make('qty_outstanding')->label('Baki Diminta')->numeric()->sortable(),
                TextColumn::make('suggested_qty')->label('Cadangan Pindah')->numeric()->sortable()
                    ->color('primary')->weight('bold'),
                TextColumn::make('current_stock')->label('Stok Donor')->numeric(),
                TextColumn::make('reason')->label('Sebab')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('from_branch')->label('Daripada Cawangan')
                    ->options(fn () => BranchDemandAllocationRecommender::recommendations()->pluck('from_branch', 'from_branch')->unique()->sort()),
                SelectFilter::make('to_branch')->label('Ke Cawangan')
                    ->options(fn () => Store::orderBy('StoreCode')->pluck('StoreCode', 'StoreCode')),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->slideOver()
                    ->modalHeading(fn ($record) => "Cadangan Penuhan: {$record->internal_code}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(fn ($record) => [
                        TextEntry::make('reason')->label('Sebab')->state($record->reason),
                        TextEntry::make('suggestion')->label('Cadangan Pindahan')->state($record->suggestion),
                    ])
                    ->extraModalFooterActions([
                        static::createTransferAction(),
                    ]),
                static::createTransferAction(),
            ])
            ->paginated([10, 25, 50])
            ->searchPlaceholder('Cari kod design / no. permintaan...');
    }

    private static function createTransferAction(): Action
    {
        return Action::make('createTransfer')
            ->label('Cipta Transfer')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('success')
            ->schema(fn ($record) => [
                Select::make('from_store')
                    ->label('Daripada Cawangan')
                    ->options(fn () => [$record->from_branch => $record->from_branch])
                    ->default($record->from_branch)
                    ->required(),
                Select::make('to_store')
                    ->label('Ke Cawangan')
                    ->options(fn () => [$record->to_branch => $record->to_branch])
                    ->default($record->to_branch)
                    ->required(),
                TextInput::make('qty')
                    ->label('Kuantiti')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue($record->qty_outstanding)
                    ->default($record->suggested_qty)
                    ->required(),
            ])
            ->action(function (array $data, $record) {
                $t = StockTransfer::create([
                    'branch_demand_request_line_id' => $record->branch_demand_request_line_id,
                    'internal_code' => $record->internal_code,
                    'item_desc' => $record->item_desc,
                    'category_code' => $record->category_name,
                    'from_store' => $data['from_store'],
                    'to_store' => $data['to_store'],
                    'qty' => $data['qty'],
                    'requested_by' => Auth::user()->name,
                ]);

                Notification::make()
                    ->title("Transfer {$t->transfer_number} dicipta utk permintaan {$record->request_number}")
                    ->success()
                    ->send();
            });
    }
}
