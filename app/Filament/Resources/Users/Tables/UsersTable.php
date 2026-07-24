<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->formatStateUsing(fn ($state): string => Str::headline($state))
                    ->colors(['info'])
                    ->badge()
                    ->toggleable(),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('set_role')
                        ->label('Set Role')
                        ->color('info')
                        ->icon('heroicon-s-shield-check')
                        ->modalIcon('heroicon-s-shield-check')
                        ->modalDescription('Select a role for the user.')
                        ->modalWidth('md')
                        ->modalAlignment(Alignment::Center)
                        ->modalFooterActionsAlignment(Alignment::Center)
                        ->form([
                            Select::make('roles')
                                ->relationship('roles', 'name')
                                ->getOptionLabelFromRecordUsing(fn (Model $record) => Str::headline($record->name))
                                ->multiple()
                                ->preload()
                                ->searchable(),
                        ])
                        ->action(
                            fn () => Notification::make()
                                ->success()
                                ->title('Updated successfully')
                                ->body('Role has been updated.')
                                ->send()
                        )
                        ->hidden(fn (User $record): bool => $record->isSuperAdmin() || ! auth()->user()->isSuperAdmin()),
                    ActionGroup::make([
                        DeleteAction::make(),
                    ])
                        ->dropdown(false),
                ])
                    ->link()
                    ->icon('heroicon-m-ellipsis-horizontal')
                    ->iconSize(IconSize::Large)
                    ->hiddenLabel()
                    ->tooltip('More Actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
