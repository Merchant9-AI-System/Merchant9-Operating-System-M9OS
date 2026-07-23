<?php

namespace App\Filament\Resources\PhysicalGoldReports;

use App\Filament\Resources\PhysicalGoldReports\Pages\CreatePhysicalGoldReport;
use App\Filament\Resources\PhysicalGoldReports\Pages\EditPhysicalGoldReport;
use App\Filament\Resources\PhysicalGoldReports\Pages\ListPhysicalGoldReports;
use App\Filament\Resources\PhysicalGoldReports\Pages\ViewPhysicalGoldReport;
use App\Filament\Resources\PhysicalGoldReports\Schemas\PhysicalGoldReportForm;
use App\Filament\Resources\PhysicalGoldReports\Schemas\PhysicalGoldReportInfolist;
use App\Filament\Resources\PhysicalGoldReports\Tables\PhysicalGoldReportsTable;
use App\Models\PhysicalGoldReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PhysicalGoldReportResource extends Resource
{
    protected static ?string $model = PhysicalGoldReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Physical Gold Balance';

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'report_date';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lines.category', 'lines.purity']);
    }

    public static function form(Schema $schema): Schema
    {
        return PhysicalGoldReportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PhysicalGoldReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhysicalGoldReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhysicalGoldReports::route('/'),
            'create' => CreatePhysicalGoldReport::route('/create'),
            'view' => ViewPhysicalGoldReport::route('/{record}'),
            'edit' => EditPhysicalGoldReport::route('/{record}/edit'),
        ];
    }
}
