<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->required(fn($record) => $record === null),
                Select::make('roles')
                    ->hidden(fn($record): bool => $record === null)
                    ->disabled(fn(User $record): bool => $record === null || $record->isSuperAdmin())
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn(Model $record) => Str::headline($record->name))
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->optionsLimit(5),
            ]);
    }
}
