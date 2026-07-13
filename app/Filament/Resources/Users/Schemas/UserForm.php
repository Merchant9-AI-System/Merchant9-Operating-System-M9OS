<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Detail')
                    ->description('Manage user details here.')
                    ->compact()
                    ->aside()
                    ->schema([
                        TextInput::make('name')
                            ->placeholder('Enter user name here.'),
                        TextInput::make('email')
                            ->placeholder('Enter user email here.')
                            ->unique(User::class, 'email', ignoreRecord: true)
                            ->email()
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Roles')
                    ->description('Manage user role details here.')
                    ->compact()
                    ->aside()
                    ->schema([
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->getOptionLabelFromRecordUsing(fn(Model $record) => Str::headline($record->name))
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->optionsLimit(5),
                    ])
                    ->hidden(fn() => !auth()->user()->hasRole('super_admin')),
                Section::make('Password')
                    ->description('Manage user password here.')
                    ->aside()
                    ->schema([
                        TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->rule(Password::default())
                            ->autocomplete('new-password')
                            ->dehydrated(fn($state): bool => filled($state))
                            ->dehydrateStateUsing(fn($state): string => Hash::make($state))
                            ->live(debounce: 500)
                            ->same('passwordConfirmation'),
                        TextInput::make('passwordConfirmation')
                            ->label(__('Password Confirmation'))
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required()
                            ->visible(fn(Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                    ])
                    ->hidden(fn(?User $record) => $record !== null),
            ])
            ->columns(1);
    }
}
