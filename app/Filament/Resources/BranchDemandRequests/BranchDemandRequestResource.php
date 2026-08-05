<?php

namespace App\Filament\Resources\BranchDemandRequests;

use App\Filament\Resources\BranchDemandRequests\Pages\CreateBranchDemandRequest;
use App\Filament\Resources\BranchDemandRequests\Pages\ListBranchDemandRequests;
use App\Filament\Resources\BranchDemandRequests\Pages\ViewBranchDemandRequest;
use App\Filament\Resources\BranchDemandRequests\RelationManagers\LinesRelationManager;
use App\Filament\Resources\BranchDemandRequests\Schemas\BranchDemandRequestForm;
use App\Filament\Resources\BranchDemandRequests\Schemas\BranchDemandRequestInfolist;
use App\Filament\Resources\BranchDemandRequests\Tables\BranchDemandRequestsTable;
use App\Models\BranchDemandRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class BranchDemandRequestResource extends Resource
{
    protected static ?string $model = BranchDemandRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Branch Demand';

    protected static string|\UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'request_number';

    /**
     * Staf cawangan (bukan hq_reviewer/ceo/super_admin) cuma nampak permintaan cawangan SENDIRI -
     * HQ/CEO/super_admin nampak semua cawangan (skop semakan).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['lines', 'submittedBy', 'reviewedBy']);

        $user = Auth::user();
        if ($user && ! $user->isSuperAdmin() && ! $user->hasRole(['hq_reviewer', 'ceo'])) {
            $query->where('store_code', $user->store_code ?? '__none__');
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return BranchDemandRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BranchDemandRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BranchDemandRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranchDemandRequests::route('/'),
            'create' => CreateBranchDemandRequest::route('/create'),
            'view' => ViewBranchDemandRequest::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }
}
