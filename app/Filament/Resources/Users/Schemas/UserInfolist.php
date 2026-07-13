<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Detail')
                    ->description('User details here.')
                    ->compact()
                    ->aside()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('email')
                            ->label('Email address'),
                        TextEntry::make('email_verified_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Roles')
                    ->description('User role details here.')
                    ->compact()
                    ->aside()
                    ->schema([
                        TextEntry::make('role')
                            ->badge()
                            ->label('Role')
                            ->placeholder('-'),
                        // ->getOptionLabelFromRecordUsing(fn(Model $record) => Str::headline($record->name)),
                    ])
                    ->hidden(fn() => !Auth::user()->hasRole('super-admin')),
            ])->columns(1);
    }
}
