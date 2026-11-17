<?php

namespace App\Filament\Resources\ChecklistItems;

use App\Filament\Resources\ChecklistItems\Pages\ManageChecklistItems;
use App\Models\ChecklistItem;
use App\Support\BranchActivityCalculator;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Templat Daily Checklist (M02) - tugasan harian setiap cawangan, diurus admin. Kebenaran Shield
 * (ChecklistItemPolicy). Ketua cawangan menanda siap di /home.
 */
class ChecklistItemResource extends Resource
{
    protected static ?string $model = ChecklistItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Daily Checklist';

    protected static string|\UnitEnum|null $navigationGroup = 'Branch Performance';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Item Checklist';

    protected static ?string $pluralModelLabel = 'Daily Checklist';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->label('Tugasan')->required()->maxLength(160)->columnSpanFull(),
                Textarea::make('description')->label('Keterangan (pilihan)')->rows(2)->columnSpanFull(),
                TimePicker::make('due_time')
                    ->label('Sebelum waktu (Malaysia)')
                    ->seconds(false)
                    ->helperText('Selepas waktu ini item yang belum siap ditanda tertunggak. Kosongkan jika tiada had.'),
                TextInput::make('sort_order')->label('Susunan')->numeric()->default(0)->required(),
                Select::make('store_codes')
                    ->label('Cawangan')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => collect(BranchActivityCalculator::branches())
                        ->map(fn (string $code): string => strtoupper(trim($code)))
                        ->unique()
                        ->mapWithKeys(fn (string $code): array => [$code => $code])
                        ->all())
                    ->helperText('Kosong = semua cawangan.')
                    ->columnSpanFull(),
                Toggle::make('is_active')->label('Aktif')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('title')->label('Tugasan')->searchable()->wrap(),
                TextColumn::make('due_time')->label('Sebelum')->formatStateUsing(fn ($state): string => $state ? substr((string) $state, 0, 5) : '-'),
                TextColumn::make('store_codes')->label('Cawangan')->badge()->placeholder('Semua'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageChecklistItems::route('/'),
        ];
    }
}
