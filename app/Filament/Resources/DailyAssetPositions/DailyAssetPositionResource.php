<?php

namespace App\Filament\Resources\DailyAssetPositions;

use App\Filament\Resources\DailyAssetPositions\Pages\CreateDailyAssetPosition;
use App\Filament\Resources\DailyAssetPositions\Pages\EditDailyAssetPosition;
use App\Filament\Resources\DailyAssetPositions\Pages\ListDailyAssetPositions;
use App\Filament\Resources\DailyAssetPositions\Pages\ViewDailyAssetPosition;
use App\Filament\Resources\DailyAssetPositions\Schemas\DailyAssetPositionForm;
use App\Filament\Resources\DailyAssetPositions\Schemas\DailyAssetPositionInfolist;
use App\Filament\Resources\DailyAssetPositions\Tables\DailyAssetPositionsTable;
use App\Models\DailyAssetPosition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * "Daily Company Asset Position" - lapisan kawalan/reconciliation harian yg dikeyin accountant,
 * berasingan drpd data JEMiSys sedia ada (rujuk App\Models\DailyAssetPosition). Accountant boleh
 * cipta/edit; admin sahaja boleh padam; semua staff berdaftar (termasuk CEO/management) boleh
 * lihat (read-only ikut role, dikawal kat sini bukan kat canAccessPanel()).
 */
class DailyAssetPositionResource extends Resource
{
    protected static ?string $model = DailyAssetPosition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Daily Asset Position';

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'entry_date';

    public static function form(Schema $schema): Schema
    {
        return DailyAssetPositionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DailyAssetPositionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DailyAssetPositionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyAssetPositions::route('/'),
            'create' => CreateDailyAssetPosition::route('/create'),
            'view' => ViewDailyAssetPosition::route('/{record}'),
            'edit' => EditDailyAssetPosition::route('/{record}/edit'),
        ];
    }

    /** Accountant/admin sahaja boleh key-in - CEO/management/staff lain read-only. */
    public static function canCreate(): bool
    {
        return static::isAccountantOrAdmin();
    }

    public static function canEdit($record): bool
    {
        return static::isAccountantOrAdmin();
    }

    /** Padam cuma utk admin (arahan eksplisit - jejak audit kekal walaupun accountant tersilap). */
    public static function canDelete($record): bool
    {
        return Auth::user()?->hasRole('admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasRole('admin') ?? false;
    }

    protected static function isAccountantOrAdmin(): bool
    {
        return Auth::user()?->hasAnyRole(['accountant', 'admin']) ?? false;
    }
}
