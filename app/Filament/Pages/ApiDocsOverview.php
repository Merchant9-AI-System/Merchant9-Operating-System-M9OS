<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasApiDocsStatus;
use App\Filament\Pages\Concerns\HasRestockToolsList;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Menu "API Docs" > Overview - rujukan MANUAL utk staf/dev (bukan UI guava/filament-mcp
 * sendiri), sbb kandungan perlu jujur ttg APA YG SEBENARNYA wujud (RestockServer tersedia
 * penuh, laluan /mcp/admin guava terpasang tapi BELUM ada resource didedahkan) - rujuk
 * routes/ai.php & AdminPanelProvider::panel()->plugin(McpPlugin::make()...).
 */
class ApiDocsOverview extends Page
{
    use HasApiDocsStatus, HasPageShield, HasRestockToolsList;

    protected string $view = 'filament.pages.api-docs-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'API Docs - Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'API Docs';

    protected static ?int $navigationSort = 1;

    public function getSubheading(): ?string
    {
        return 'Cara agen AI (Claude & lain-lain) sambung ke M9OS via Model Context Protocol (MCP).';
    }
}
