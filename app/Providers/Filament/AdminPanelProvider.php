<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\ActionAlerts;
use App\Filament\Widgets\BranchHealthTable;
use App\Filament\Widgets\CapitalAgingChart;
use App\Filament\Widgets\CapitalAgingSummary;
use App\Filament\Widgets\CeoActionCentre;
use App\Filament\Widgets\GoldVsIdealByBranch;
use App\Filament\Widgets\InventoryKpiStats;
use App\Filament\Widgets\StockVsOptimumChart;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->brandName('Merchant9 OS')
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                // CEO Dashboard Phase 1 - boleh dimatikan via .env (config/dashboard.php),
                // widget lain di bawah TIDAK berubah.
                CeoActionCentre::class,
                InventoryKpiStats::class,
                ActionAlerts::class,
                CapitalAgingChart::class,
                CapitalAgingSummary::class,
                GoldVsIdealByBranch::class,
                BranchHealthTable::class,
                StockVsOptimumChart::class,
                // AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
