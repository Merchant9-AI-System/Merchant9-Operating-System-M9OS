<?php

namespace App\Filament\Resources\ExpenseClaims\Schemas;

use App\Models\ExpenseClaim;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExpenseClaimInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maklumat Claim')
                    ->collapsible()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('claim_number')->label('No. Claim'),
                                TextEntry::make('claimant_name')->label('Claimant'),
                                TextEntry::make('designation')->label('Jawatan')->placeholder('-'),
                                TextEntry::make('createdBy.name')->label('Dicipta oleh')->placeholder('-'),
                                TextEntry::make('claim_month')->label('Bulan')->date('F Y'),
                                TextEntry::make('status')->label('Status')->badge()
                                    ->color(fn(string $state) => match ($state) {
                                        ExpenseClaim::STATUS_SUBMITTED => 'warning',
                                        ExpenseClaim::STATUS_APPROVED => 'success',
                                        ExpenseClaim::STATUS_REJECTED => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('total_amount')->label('Jumlah')->money('MYR'),
                                TextEntry::make('submitted_at')->label('Tarikh Hantar')->dateTime('d/m/Y H:i')->placeholder('-'),
                                TextEntry::make('approvedBy.name')->label('Diluluskan/Ditolak oleh')->placeholder('-'),
                                TextEntry::make('approved_at')->label('Tarikh Keputusan')->dateTime('d/m/Y H:i')->placeholder('-'),
                                TextEntry::make('rejection_reason')->label('Sebab Ditolak')->placeholder('-')->columnSpanFull()
                                    ->visible(fn(ExpenseClaim $record) => filled($record->rejection_reason)),
                                TextEntry::make('notes')->label('Nota')->placeholder('-')->columnSpanFull(),
                            ]),
                    ])->columnSpanFull(),
            ]);
    }
}
