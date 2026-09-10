<?php

namespace App\Filament\Widgets;

use App\Models\StockTransfer;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class ActiveStockTransfersTable extends TableWidget
{
    protected static ?string $heading = 'Item Yang Dah Ada Stock Transfer';

    // Separuh (1 drpd 2 lajur grid lalai Dashboard/footer widget) - bersebelahan dgn
    // ActiveStockRearrangementStopsTable, bukan bertindih penuh.
    protected int|string|array $columnSpan = 1;

    // Fallback - refresh berkala (cth. staf LAIN create/cancel transfer drpd tab/browser
    // berlainan) BERSAMA #[On('rearrangement-lists-updated')] bawah, yg refresh SERTA-MERTA
    // lepas "Cipta Transfer"/"Stop Rearrange" di page ni sendiri (rujuk StockRearrangementRecommendation).
    protected ?string $pollingInterval = '30s';

    // Widget ni Livewire component BERASINGAN drpd page StockRearrangementRecommendation -
    // dispatch('rearrangement-lists-updated') dari action Cipta Transfer/Stop Rearrange kat
    // page tu TAK auto refresh widget ni (component lain), perlu listener eksplisit ni. Method
    // kosong dah cukup - Livewire re-render component bila listener event fire, ->query() atas
    // (table()) re-evaluate fresh setiap render, tiada cache perlu di-reset manual.
    #[On('rearrangement-lists-updated')]
    public function refreshOnRearrangementUpdate(): void
    {
        //
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->scopedQuery())
            ->columns([
                TextColumn::make('transfer_number')->label('No. Transfer')->searchable()->sortable(),
                TextColumn::make('internal_code')->label('Kod Design')->searchable(),
                TextColumn::make('item_desc')->label('Jenis Item')->limit(25),
                TextColumn::make('from_store')->label('Dari')->badge()->color('success'),
                TextColumn::make('to_store')->label('Ke')->badge()->color('danger'),
                TextColumn::make('qty')->label('Kuantiti')->numeric(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        StockTransfer::STATUS_REQUESTED => 'gray',
                        StockTransfer::STATUS_IN_TRANSIT => 'warning',
                        StockTransfer::STATUS_RECEIVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('requested_by')->label('Diminta oleh'),
                TextColumn::make('requested_at')->label('Tarikh Minta')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (StockTransfer $record) => route('filament.admin.resources.stock-transfers.view', ['record' => $record->getKey()]))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    /**
     * User TIADA cawangan (store_code null/kosong - HQ/CEO/super_admin) nampak SEMUA rekod;
     * user ADA cawangan hanya nampak rekod yg from_store ATAU to_store sepadan cawangan dia.
     * LOWER(TRIM()) SQL - from_store/to_store yg dicipta drpd page ni/Rearrange/
     * BranchDemandAllocationSuggestion kekal RAW/padded (rujuk dokblok StockTransfer::
     * notifyBranches()), User::store_code pula sentiasa trim()-ed - padanan terus tanpa
     * normalize akan gagal senyap (sama isu spt $activeTransferKeys di page utama).
     */
    protected function scopedQuery(): Builder
    {
        $userStore = trim((string) Auth::user()?->store_code);

        $query = StockTransfer::query()->where('status', '!=', StockTransfer::STATUS_CANCELLED);

        if ($userStore !== '') {
            $needle = mb_strtolower($userStore);
            $query->where(function (Builder $q) use ($needle) {
                $q->whereRaw('LOWER(TRIM(from_store)) = ?', [$needle])
                    ->orWhereRaw('LOWER(TRIM(to_store)) = ?', [$needle]);
            });
        }

        return $query;
    }
}
