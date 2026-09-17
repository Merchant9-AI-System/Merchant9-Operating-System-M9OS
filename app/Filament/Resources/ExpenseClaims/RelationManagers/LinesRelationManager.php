<?php

namespace App\Filament\Resources\ExpenseClaims\RelationManagers;

use App\Models\ExpenseClaimLine;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Paparan sahaja - line diurus SEPENUHNYA via halaman Inertia staf (ExpenseClaimController),
 * bukan CRUD ad-hoc di sini, ikut corak BranchDemandRequests\RelationManagers\LinesRelationManager.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Item Claim';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn($query) => $query->with('charges'))
            ->columns([
                TextColumn::make('expense_date')->toggleable()->label('Tarikh')->date('d/m/Y')->sortable(),
                TextColumn::make('description')->toggleable()->label('Keterangan')->searchable()->wrap(),
                TextColumn::make('category')->toggleable()->label('Kategori')->badge(),
                // IconColumn::make('is_vendor_invoice')->toggleable()->label('Invois Vendor')->boolean(),
                // Breakdown invois vendor (Google/Facebook/TikTok Ads, dll.) - CEO perlu nampak
                // butiran ni SEBELUM approve, bukan cuma 1 jumlah lump sum (rujuk contoh invois
                // Google Ads: byk baris "description: amount" di bawah 1 invois yg sama).
                // TextColumn::make('vendor')->toggleable()->label('Vendor')->placeholder('-')->toggleable(),
                // TextColumn::make('invoice_number')->toggleable()->label('No. Invois')->placeholder('-')->toggleable(),
                // TextColumn::make('charges')
                //     ->label('Caj (Description : Amount)')
                //     ->state(fn(ExpenseClaimLine $record) => $record->charges
                //         ->map(fn($c) => "{$c->description}: RM " . number_format((float) $c->amount, 2))
                //         ->all())
                //     ->listWithLineBreaks()
                //     ->bulleted()
                //     ->placeholder('-')
                //     ->wrap(),
                TextColumn::make('charges')
                    ->label('Gross (RM)')
                    ->toggleable()
                    ->state(function (ExpenseClaimLine $record) {
                        $charge = $record->charges->firstWhere('description', 'Amount');

                        return $charge ? 'RM ' . number_format((float) $charge->amount, 2) : null;
                    })
                    ->placeholder('-'),
                // TextColumn::make('charges')
                //     ->label('Tax (RM)')
                //     ->state(function (ExpenseClaimLine $record) {
                //         $charge = $record->charges->firstWhere('description', 'Amount');

                //         return $charge ? 'RM ' . number_format((float) $charge->amount, 2) : null;
                //     })
                //     ->placeholder('-'),
                TextColumn::make('amount')->toggleable()->label('Net (RM)')->money('MYR')->sortable()->weight('bold')->summarize(Sum::make()),
                TextColumn::make('wht')->toggleable()->label('Rate')->state(fn(ExpenseClaimLine $record) => $record->wht . '%')->placeholder('-'),
                TextColumn::make('wht_amount')->toggleable()->label('WHT Amount')->numeric(2)->sortable()->weight('bold'),
                TextColumn::make('remarks')->toggleable()->label('Catatan')->placeholder('-')->wrap(),
                TextColumn::make('receipt_url')->toggleable()->label('Resit/Invois')
                    ->formatStateUsing(fn(?string $state) => filled($state) ? 'Lihat' : '-')
                    ->url(fn(?string $state) => $state, true)
                    ->color('primary'),
            ])
            ->filters([
                // Kategori kini free text (rujuk ExpenseClaimLine dokblok, bukan enum tertutup)
                // - pilihan filter diambil terus drpd nilai SEDIA ADA dlm DB (dynamic), bukan
                // senarai statik yg dah lapuk.
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn() => ExpenseClaimLine::query()->distinct()
                        ->orderBy('category')->pluck('category', 'category')),
                // TernaryFilter::make('is_vendor_invoice')
                //     ->label('Invois Vendor'),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()->slideOver()->schema([
                    // TextEntry::make('expense_date')->label('Tarikh')->date('d/m/Y'),
                    // TextEntry::make('description')->label('Keterangan'),
                    // TextEntry::make('category')->label('Kategori')->badge(),
                    IconEntry::make('is_vendor_invoice')->label('Invois Vendor')->boolean(),
                    TextEntry::make('vendor')->label('Vendor')->placeholder('-')
                        ->visible(fn(ExpenseClaimLine $record) => $record->is_vendor_invoice),
                    TextEntry::make('invoice_number')->label('No. Invois')->placeholder('-')
                        ->visible(fn(ExpenseClaimLine $record) => $record->is_vendor_invoice),
                    RepeatableEntry::make('charges')
                        ->label('Caj (Breakdown Invois)')
                        ->schema([
                            TextEntry::make('description')->label('Keterangan Caj'),
                            TextEntry::make('amount')->label('Jumlah')->money('MYR'),
                        ])
                        ->columns(2)
                        ->visible(fn(ExpenseClaimLine $record) => $record->is_vendor_invoice),
                    // TextEntry::make('wht')->label('WHT')
                    //     ->formatStateUsing(fn(?string $state) => filled($state) ? "{$state}%" : '-')
                    //     ->visible(fn(ExpenseClaimLine $record) => $record->is_vendor_invoice),
                    // TextEntry::make('wht_amount')->label('Jumlah WHT')->money('MYR')->placeholder('-')
                    //     ->visible(fn(ExpenseClaimLine $record) => $record->is_vendor_invoice),
                    // TextEntry::make('amount')->label('Jumlah')->money('MYR')->weight('bold'),
                    // TextEntry::make('remarks')->label('Catatan')->placeholder('-'),
                    // TextEntry::make('receipt_url')->label('Resit/Invois')
                    //     ->formatStateUsing(fn(?string $state) => filled($state) ? 'Lihat' : '-')
                    //     ->url(fn(?string $state) => $state, true)
                    //     ->color('primary')
                    //     ->visible(fn(?string $state) => filled($state)),
                ]),
            ])
            ->toolbarActions([])
            ->defaultSort('expense_date', 'desc');
    }
}
