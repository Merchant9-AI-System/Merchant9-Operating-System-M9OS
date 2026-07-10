<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\ActionAlerts;
use App\Filament\Widgets\BranchHealthTable;
use App\Filament\Widgets\CapitalAgingChart;
use App\Filament\Widgets\CapitalAgingSummary;
use App\Filament\Widgets\CeoActionCentre;
use App\Filament\Widgets\DailyAssetPositionCashChart;
use App\Filament\Widgets\DailyAssetPositionReconciliation;
use App\Filament\Widgets\DailyAssetPositionStockChart;
use App\Filament\Widgets\DailyAssetPositionSummary;
use App\Filament\Widgets\DailyAssetPositionSupplierChart;
use App\Filament\Widgets\GoldVsIdealByBranch;
use App\Filament\Widgets\InventoryKpiStats;
use App\Filament\Widgets\StockVsOptimumChart;
use App\Filament\Widgets\UserWidget;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
                'secondary' => Color::Zinc,
            ])
            ->brandName('Merchant9 OS')
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearch(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Analisis JEMiSys')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Accounting')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Procurement')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Inventory Health')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Data Management')
                    ->collapsible(false),
            ])
            ->widgets([
                UserWidget::class,
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
                // Daily Asset Position (accountant-keyed reconciliation layer) - boleh dimatikan
                // via .env CEO_DAILY_ASSET_POSITION_ENABLED=false (config/dashboard.php).
                DailyAssetPositionSummary::class,
                DailyAssetPositionStockChart::class,
                DailyAssetPositionCashChart::class,
                DailyAssetPositionSupplierChart::class,
                DailyAssetPositionReconciliation::class,
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
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationLabel('Roles')
                    ->navigationGroup('Data Management')
                    ->gridColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 4,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                    ]),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
