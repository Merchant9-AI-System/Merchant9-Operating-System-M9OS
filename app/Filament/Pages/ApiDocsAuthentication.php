<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasApiDocsStatus;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Menu "API Docs" > Authentication & Tokens - rujuk App\Filament\Resources\Users\
 * RelationManagers\TokensRelationManager (Sanctum) & App\Providers\AppServiceProvider
 * (Passport::authorizationView) utk konteks penuh dua kaedah auth ni.
 */
class ApiDocsAuthentication extends Page
{
    use HasApiDocsStatus, HasPageShield;

    protected string $view = 'filament.pages.api-docs-authentication';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Authentication & Tokens';

    protected static ?string $title = 'API Docs - Authentication & Tokens';

    protected static string|\UnitEnum|null $navigationGroup = 'API Docs';

    protected static ?int $navigationSort = 3;

    public function getSubheading(): ?string
    {
        return 'Dua cara sah masuk ke MCP server M9OS - pilih ikut jenis client.';
    }
}
