<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        
        Route::bind('act', function ($value) {
            return \App\Models\ContractPerformanceAct::findOrFail($value);
        });

        $this->routes(function () {
            // Public API Routes (no authentication required)
            Route::middleware('api')
                ->prefix('api/public')
                ->as('api.public.')
                ->group(function() {
                    require base_path('routes/api/public.php');
                });

            // Mobile API Routes
            Route::middleware('api')
                ->prefix('api/v1/mobile')
                ->as('api.v1.mobile.')
                ->group(function() {
                     require base_path('routes/api/v1/mobile/auth.php');
                     require base_path('routes/api/v1/mobile/log.php');
                     require base_path('routes/api/v1/mobile/projects.php');
                     require base_path('routes/api/v1/mobile/catalogs.php');
                });

            // Landing API Routes
            Route::middleware('api')
                ->prefix('api/v1/landing')
                ->as('api.v1.landing.')
                 ->group(function() {
                     require base_path('routes/api/v1/landing/auth.php');
                     require base_path('routes/api/v1/landing/billing.php');
                     require base_path('routes/api/v1/landing/landing_admin_auth.php');
                     require base_path('routes/api/v1/landing/landing_admins.php');
                     require base_path('routes/api/v1/landing_admin_blog.php');
                     
                     // Holding API Routes (объединены: лендинг, отчеты, публичные данные)
                     require base_path('routes/api/v1/landing/holding.php');
                 });

            // Admin API Routes
            Route::middleware(['api', 'auth:api_admin', 'auth.jwt:api_admin', 'organization.context'])
                ->prefix('api/v1/admin')
                ->as('api.v1.admin.')
                ->group(function() {
                    if (file_exists(base_path('routes/api/v1/admin/catalogs.php'))) {
                        require base_path('routes/api/v1/admin/catalogs.php');
                    }
                    if (file_exists(base_path('routes/api/v1/admin/projects.php'))) {
                        require base_path('routes/api/v1/admin/projects.php');
                    }
                    if (file_exists(base_path('routes/api/v1/admin/users.php'))) {
                        require base_path('routes/api/v1/admin/users.php');
                    }
                    if (file_exists(base_path('routes/api/v1/admin/reports.php'))) {
                        require base_path('routes/api/v1/admin/reports.php');
                    }
                });

            // Web Routes
            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Добавьте другие ограничители, если необходимо (например, для web)
        /*
        RateLimiter::for('web', function (Request $request) {
            return Limit::perMinute(60)->by($request->session()->get('id') ?: $request->ip());
        });
        */
    }
}