<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\SystemAdminLogin;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditSystemAdminProfile;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Widgets\NotificationDeliveryStatsWidget;
use App\Filament\Widgets\PlatformGrowthStatsWidget;
use App\Filament\Widgets\PlatformHealthStatsWidget;
use App\Filament\Widgets\PlatformRiskStatsWidget;
use App\Filament\Widgets\SaaSIncomeStatsWidget;
use App\Filament\Widgets\UsersStatsWidget;
use App\Http\Middleware\EnsureSystemAdminSessionIsFresh;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function trans_message;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme([
                'resources/css/filament/admin/theme.css',
                'resources/js/filament/blog-inline-block-editor.js',
            ])
            ->login(SystemAdminLogin::class)
            ->profile(EditSystemAdminProfile::class, isSimple: false)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
                SaaSIncomeStatsWidget::class,
                UsersStatsWidget::class,
                PlatformHealthStatsWidget::class,
                PlatformGrowthStatsWidget::class,
                PlatformRiskStatsWidget::class,
                NotificationDeliveryStatsWidget::class,
            ])
            ->navigationGroups(NavigationGroups::panelGroups())
            ->navigationItems([
                NavigationItem::make(trans_message('filament_navigation.api_docs.label'))
                    ->url('/docs/api', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-book-open')
                    ->group(NavigationGroups::settings())
                    ->visible(fn (): bool => SystemAdminAccess::can(FilamentPermission::API_DOCS_VIEW))
                    ->sort(20),
            ])
            ->authGuard('system_admin')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureSystemAdminSessionIsFresh::class,
            ], isPersistent: true);
    }
}
