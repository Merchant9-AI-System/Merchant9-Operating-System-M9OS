<?php

namespace App\Filament\Widgets;

use App\Models\StockRearrangementStop;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

class ActiveStockRearrangementStopsTable extends TableWidget
{
    protected static ?string $heading = 'Item Yang Dah Ada Stop Rearrange/Request';

    // Separuh (1 drpd 2 lajur grid lalai Dashboard/footer widget) - bersebelahan dgn
    // ActiveStockTransfersTable, bukan bertindih penuh.
    protected int|string|array $columnSpan = 1;

    // Fallback - refresh berkala BERSAMA #[On('rearrangement-lists-updated')] bawah, yg
    // refresh SERTA-MERTA lepas "Cipta Transfer"/"Stop Rearrange" (rujuk
    // StockRearrangementRecommendation & ActiveStockTransfersTable, corak sama).
    protected ?string $pollingInterval = '30s';

    #[On('rearrangement-lists-updated')]
    public function refreshOnRearrangementUpdate(): void
    {
        //
    }

    public function table(Table $table): Table
    {
        return $table
            // Tiada skop cawangan - StockRearrangementStop TIADA lajur from_store/to_store,
            // exclusion dia sendiri pun GLOBAL (rujuk ACTIVE_STATUSES & dokblok model), jadi
            // semua user nampak senarai sama. Rejected TERUS softdelete (rujuk
            // StockRearrangementStop::reject()), so query biasa dah cukup exclude - whereIn
            // di sini sekadar jelas/defensive, sepadan gaya blok exclusion di page utama.
            ->query(StockRearrangementStop::whereIn('status', StockRearrangementStop::ACTIVE_STATUSES))
            ->columns([
                TextColumn::make('internal_code')->label('Kod Design')->searchable(),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25),
                TextColumn::make('reason')->label('Sebab')->wrap()->limit(80)
                    ->extraHeaderAttributes(['style' => 'min-width: 20rem'])
                    ->extraCellAttributes(['style' => 'min-width: 20rem']),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        StockRearrangementStop::STATUS_PENDING => 'warning',
                        StockRearrangementStop::STATUS_APPROVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('requested_by')->label('Diminta oleh'),
                TextColumn::make('requested_at')->label('Tarikh Diminta')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
