<?php

namespace App\Filament\Resources\BranchMonthlyTargets\RelationManagers;

use App\Models\BranchMonthlyTarget;
use App\Models\BranchMonthlyTargetRevision;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Sejarah semua perubahan sasaran ini (baca sahaja): nilai lama -> baharu, catatan, siapa dan bila. */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Sejarah semakan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Bila')->dateTime('d/m/Y H:i', 'Asia/Kuala_Lumpur'),
                TextColumn::make('changed_by_name')
                    ->label('Oleh')
                    ->description(fn (BranchMonthlyTargetRevision $record): string => $record->source === BranchMonthlyTarget::SOURCE_BRANCH ? 'Cawangan' : 'HQ'),
                TextColumn::make('rm')
                    ->label('Sasaran RM')
                    ->state(fn (BranchMonthlyTargetRevision $record): string => ($record->old_rm === null ? 'Baharu' : number_format((float) $record->old_rm, 2)).' → '.number_format((float) $record->new_rm, 2)),
                TextColumn::make('grams')
                    ->label('Sasaran gram')
                    ->state(fn (BranchMonthlyTargetRevision $record): string => ($record->old_grams === null ? 'Baharu' : number_format((float) $record->old_grams, 2)).' → '.number_format((float) $record->new_grams, 2)),
                TextColumn::make('remark')->label('Catatan')->wrap()->placeholder('-'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated(false);
    }
}
