<?php

namespace App\Filament\Pages;

use App\Models\Jemisys\InventoryPiece;
use App\Models\StockTransfer;
use App\Support\RearrangeCalculator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Cadangan pindah stok antara cawangan - port terus daripada Flask /rearrange
 * (rujuk RearrangeCalculator utk formula, disahkan padan 100% dgn analytics.py Python).
 */
class Rearrange extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.rearrange';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Rearrange';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => InventoryPiece::hydrate(
                RearrangeCalculator::recommendations()->all()
            ))
            ->columns([
                TextColumn::make('internal_code')->label('Kod Design')->searchable(),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25),
                TextColumn::make('category_name')->label('Kategori')->badge(),
                TextColumn::make('vendor_name')->label('Supplier'),
                TextColumn::make('total_move')->label('Unit Pindah')->numeric()->sortable()->badge()->color('primary'),
                TextColumn::make('receivers')->label('Cawangan Perlu (sold out, pernah jual)')->wrap(),
                TextColumn::make('donors')->label('Cawangan Ada Lebih (donor)')->wrap(),
                TextColumn::make('suggestion')->label('Cadangan Pindahan')->wrap(),
            ])
            ->recordActions([
                Action::make('createTransfer')
                    ->label('Cipta Transfer')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->schema(function ($record) {
                        // Ambil pasangan (from,to) pertama drpd suggestion sbg default - staff boleh ubah.
                        return [
                            Select::make('from_store')
                                ->label('Daripada Cawangan')
                                ->options(fn () => collect(explode(', ', $record->donors))
                                    ->mapWithKeys(fn ($s) => [trim(explode(' ', $s)[0]) => $s]))
                                ->required(),
                            Select::make('to_store')
                                ->label('Ke Cawangan')
                                ->options(fn () => collect(explode(', ', $record->receivers))
                                    ->mapWithKeys(fn ($s) => [trim(explode(' ', $s)[0]) => $s]))
                                ->required(),
                            TextInput::make('qty')->label('Kuantiti')->numeric()->minValue(1)->default(1)->required(),
                        ];
                    })
                    ->action(function (array $data, $record) {
                        $t = StockTransfer::create([
                            'internal_code' => $record->internal_code,
                            'item_desc' => $record->item_desc,
                            'category_code' => $record->category,
                            'from_store' => $data['from_store'],
                            'to_store' => $data['to_store'],
                            'qty' => $data['qty'],
                            'requested_by' => Auth::user()->name,
                        ]);
                        Notification::make()->title("Transfer {$t->transfer_number} dicipta")->success()->send();
                    }),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultSort('total_move', 'desc');
    }
}
