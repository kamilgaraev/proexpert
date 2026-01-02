<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Models\EstimateItem;

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
        
        // Route Model Binding для estimate, section, item УДАЛЕНЫ
        // Контроллеры теперь сами загружают модели и проверяют организацию
        
        // Явный binding для item с проверкой организации
        Route::bind('item', function ($value) {
            Log::info('[RouteServiceProvider::bind item] ===== НАЧАЛО РЕЗОЛВИНГА =====', [
                'timestamp' => now()->toIso8601String(),
                'request_id' => uniqid('bind_', true),
            ]);
            
            Log::info('[RouteServiceProvider::bind item] Начало резолвинга', [
                'value' => $value,
                'value_type' => gettype($value),
                'int_value' => (int)$value,
                'route' => request()->route()?->getName(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'route_params' => request()->route()?->parameters(),
            ]);
            
            $item = EstimateItem::withTrashed()
                ->where('id', (int)$value)
                ->first();
            
            Log::info('[RouteServiceProvider::bind item] Результат поиска', [
                'value' => $value,
                'item_found' => $item !== null,
                'item_id' => $item?->id,
                'item_estimate_id' => $item?->estimate_id,
                'item_deleted_at' => $item?->deleted_at,
            ]);
            
            if (!$item) {
                Log::warning('[RouteServiceProvider::bind item] Элемент не найден', [
                    'value' => $value,
                    'int_value' => (int)$value,
                ]);
                abort(404, 'Позиция сметы не найдена');
            }
            
            // Загружаем связь estimate (включая удаленные)
            $item->load(['estimate' => function ($query) {
                $query->withTrashed();
            }]);
            
            Log::info('[RouteServiceProvider::bind item] После загрузки estimate', [
                'item_id' => $item->id,
                'estimate_loaded' => $item->relationLoaded('estimate'),
                'estimate_exists' => $item->estimate !== null,
                'estimate_id' => $item->estimate?->id,
                'estimate_organization_id' => $item->estimate?->organization_id,
                'estimate_deleted_at' => $item->estimate?->deleted_at,
            ]);
            
            $user = request()->user();
            Log::info('[RouteServiceProvider::bind item] Информация о пользователе', [
                'user_exists' => $user !== null,
                'user_id' => $user?->id,
                'current_organization_id' => $user?->current_organization_id,
            ]);
            
            if ($user && $user->current_organization_id) {
                // Если estimate не найден, возвращаем 404
                if (!$item->estimate) {
                    Log::warning('[RouteServiceProvider::bind item] Estimate не найден для элемента', [
                        'item_id' => $item->id,
                        'item_estimate_id' => $item->estimate_id,
                    ]);
                    abort(404, 'Смета для этой позиции не найдена');
                }
                
                // Проверяем организацию
                $itemOrgId = (int)$item->estimate->organization_id;
                $userOrgId = (int)$user->current_organization_id;
                
                Log::info('[RouteServiceProvider::bind item] Проверка организации', [
                    'item_id' => $item->id,
                    'estimate_id' => $item->estimate->id,
                    'item_organization_id' => $itemOrgId,
                    'user_organization_id' => $userOrgId,
                    'match' => $itemOrgId === $userOrgId,
                ]);
                
                if ($itemOrgId !== $userOrgId) {
                    Log::warning('[RouteServiceProvider::bind item] Организация не совпадает', [
                        'item_id' => $item->id,
                        'item_organization_id' => $itemOrgId,
                        'user_organization_id' => $userOrgId,
                    ]);
                    abort(403, 'У вас нет доступа к этой позиции сметы');
                }
            }
            
            Log::info('[RouteServiceProvider::bind item] Успешное резолвинг', [
                'item_id' => $item->id,
                'estimate_id' => $item->estimate?->id,
            ]);
            
            return $item;
        });
        
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