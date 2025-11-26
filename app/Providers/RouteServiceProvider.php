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
        
        // Route Model Binding для project - только находим, проверку доступа делаем в контроллере
        Route::bind('project', function ($value) {
            return \App\Models\Project::findOrFail($value);
        });
        
        // Route Model Binding для estimate, section, item УДАЛЕНЫ
        // Контроллеры теперь сами загружают модели и проверяют организацию
        
        Route::bind('template', function ($value) {
            $template = \App\Models\EstimateTemplate::findOrFail($value);
            
            $user = request()->user();
            if ($user && $user->current_organization_id) {
                if (!$template->is_public && $template->organization_id !== $user->current_organization_id) {
                    abort(403, 'У вас нет доступа к этому шаблону сметы');
                }
            }
            
            return $template;
        });
        
        Route::bind('version', function ($value) {
            $version = \App\Models\Estimate::findOrFail($value);
            
            $user = request()->user();
            if ($user && $user->current_organization_id) {
                if ($version->organization_id !== $user->current_organization_id) {
                    abort(403, 'У вас нет доступа к этой версии сметы');
                }
            }
            
            return $version;
        });
        
        Route::bind('completed_work', function ($value) {
            $completedWork = \App\Models\CompletedWork::findOrFail($value);
            
            $user = request()->user();
            if ($user && $user->current_organization_id) {
                if ($completedWork->organization_id !== $user->current_organization_id) {
                    abort(403, 'У вас нет доступа к этой выполненной работе');
                }
            }
            
            return $completedWork;
        });
        
        Route::bind('payment', function ($value) {
            $payment = \App\Models\ContractPayment::findOrFail($value);
            
            $user = request()->user();
            if ($user && $user->current_organization_id) {
                $contract = $payment->contract;
                if ($contract && $contract->organization_id !== $user->current_organization_id) {
                    abort(403, 'У вас нет доступа к этому платежу');
                }
            }
            
            return $payment;
        });

        $this->routes(function () {
            // Public API Routes (no authentication required)
            Route::middleware('api')
                ->prefix('api/public')
                ->as('api.public.')
                ->group(function() {
                    require base_path('routes/api/public.php');
                });

            // Holding API Routes (v1) - публичные и защищенные
            Route::middleware('api')
                ->prefix('api/v1/holding-api')
                ->as('api.v1.holdingApi.')
                ->group(base_path('routes/api/v1/holding-api.php'));

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
                    if (file_exists(base_path('routes/api/v1/admin/error-tracking.php'))) {
                        require base_path('routes/api/v1/admin/error-tracking.php');
                    }
                    
                    if (file_exists(base_path('routes/api/estimates-enterprise.php'))) {
                        require base_path('routes/api/estimates-enterprise.php');
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
        // Основной API rate limiter - ВРЕМЕННО увеличен для нагрузочного тестирования
        RateLimiter::for('api', function (Request $request) {
            // ДЛЯ ТЕСТА: 100K запросов в минуту
            if ($request->user()) {
                return Limit::perMinute(100000)->by($request->user()->id);
            }
            
            // Для неаутентифицированных (по IP) - 50K запросов в минуту
            return Limit::perMinute(50000)->by($request->ip());
        });
        
        // Dashboard rate limiter - ВРЕМЕННО увеличен для нагрузочного тестирования
        RateLimiter::for('dashboard', function (Request $request) {
            // ДЛЯ ТЕСТА: 100K запросов в минуту
            if ($request->user()) {
                return Limit::perMinute(100000)->by($request->user()->id);
            }
            
            return Limit::perMinute(50000)->by($request->ip());
        });
        
        // Публичные эндпоинты (более строгий лимит)
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
        
        // Auth endpoints (защита от брутфорса)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}