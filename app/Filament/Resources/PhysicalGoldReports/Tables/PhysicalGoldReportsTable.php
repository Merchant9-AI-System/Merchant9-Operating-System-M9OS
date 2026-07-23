<?php

namespace App\Filament\Resources\PhysicalGoldReports\Tables;

use App\Models\PhysicalGoldReport;
use App\Models\User;
use App\Support\PhysicalGoldReportCalculator;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PhysicalGoldReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('report_date')->label('Tarikh')->date('d/m/Y')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PhysicalGoldReport::STATUS_DRAFT => 'gray',
                        PhysicalGoldReport::STATUS_SUBMITTED => 'warning',
                        PhysicalGoldReport::STATUS_APPROVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('net_pure_weight')->label('Physical Net Pure Gold')
                    ->state(fn (PhysicalGoldReport $record) => number_format(PhysicalGoldReportCalculator::netPureWeight($record), 4).' g'),
                TextColumn::make('prepared_by')->label('Disediakan oleh'),
                TextColumn::make('approved_by')->label('Diluluskan oleh')->placeholder('-'),
                TextColumn::make('created_at')->label('Dicipta')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        PhysicalGoldReport::STATUS_DRAFT => 'Draft',
                        PhysicalGoldReport::STATUS_SUBMITTED => 'Submitted',
                        PhysicalGoldReport::STATUS_APPROVED => 'Approved',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (PhysicalGoldReport $record) => $record->status === PhysicalGoldReport::STATUS_DRAFT),

                Action::make('submit')
                    ->label('Hantar')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Luluskan laporan fizikal emas?')
                    ->schema([
                        Select::make('recipient_user_ids')
                            ->label('Notify Users')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->visible(fn (PhysicalGoldReport $record) => $record->status === PhysicalGoldReport::STATUS_DRAFT)
                    ->action(function (PhysicalGoldReport $record, array $data) {
                        $record->submit(Auth::user());

                        $recipients = User::whereIn('id', $data['recipient_user_ids'])->get()->all();

                        Notification::make()
                            ->title('Pemberitahuan laporan fizikal emas')
                            ->body(
                                'Laporan fizikal emas telah dihantar oleh '.Auth::user()->name.'.'
                            )
                            ->info()
                            ->actions([
                                Action::make('gotoPage')->label('View')
                                    ->url(route('filament.admin.resources.physical-gold-reports.view', ['record' => $record->getKey()]))
                                    ->button(),
                            ])
                            ->sendToDatabase($recipients);

                        Notification::make()
                            ->title('Laporan dihantar utk kelulusan.')
                            ->success()
                            ->send();
                    }),

                // Kelulusan digerbang oleh kebenaran khusus (bukan role dikeraskan) supaya admin
                // boleh tetapkan role mana yg layak lulus via Roles -> Custom Permissions, PLUS
                // penyedia laporan tidak boleh meluluskan laporan sendiri (kawalan maker-checker
                // teras - diperkuat sekali lagi di model PhysicalGoldReport::approve()).
                Action::make('approve')
                    ->label('Lulus')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PhysicalGoldReport $record) => $record->status === PhysicalGoldReport::STATUS_SUBMITTED
                        && $record->prepared_by_id !== Auth::id()
                        && (bool) Auth::user()?->can('Approve:PhysicalGoldReport'))
                    ->action(function (PhysicalGoldReport $record) {
                        $record->approve(Auth::user());

                        Notification::make()
                            ->title('Laporan diluluskan.')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Tolak')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PhysicalGoldReport $record) => $record->status === PhysicalGoldReport::STATUS_SUBMITTED
                        && $record->prepared_by_id !== Auth::id()
                        && (bool) Auth::user()?->can('Approve:PhysicalGoldReport'))
                    ->action(function (PhysicalGoldReport $record) {
                        $record->reject(Auth::user());

                        Notification::make()
                            ->title('Laporan diluluskan.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('report_date', 'desc');
    }
}
