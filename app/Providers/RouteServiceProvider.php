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
        
        // Route Model Binding для актов с проверкой принадлежности организации
        Route::bind('act', function ($value) {
            $user = request()->user();
            if (!$user) {
                Log::error('RouteServiceProvider: No authenticated user for act binding', ['act_id' => $value]);
                abort(401);
            }
            
            $organizationId = $user->organization_id ?? $user->current_organization_id;
            if (!$organizationId) {
                Log::error('RouteServiceProvider: No organization for user', ['user_id' => $user->id, 'act_id' => $value]);
                abort(400, 'Не определена организация пользователя');
            }
            
            Log::info('RouteServiceProvider: Searching for act', [
                'act_id' => $value,
                'user_id' => $user->id,
                'organization_id' => $organizationId
            ]);
            
            $act = \App\Models\ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->find($value);
            
            if (!$act) {
                Log::error('RouteServiceProvider: Act not found or access denied', [
                    'act_id' => $value,
                    'user_id' => $user->id,
                    'organization_id' => $organizationId
                ]);
                abort(404, 'Акт не найден');
            }
            
            return $act;
        });

        $this->routes(function () {
            // Admin Auth Routes (loaded separately, login accessible without auth middleware)
            Route::middleware('api') // Базовый middleware для API
                ->prefix('api/v1/admin')
                ->as('api.v1.admin.') // Можно использовать то же имя группы для удобства
                ->group(base_path('routes/api/v1/admin/auth.php'));

            // Other Admin API Routes (protected by auth and access gates)
            Route::middleware(['api'])
                ->prefix('api/v1/admin')
                ->as('api.v1.admin.')
                ->group(function () {
                    // Дополнительные middleware применяем в цепочке отдельно
                    Route::middleware(['auth:api_admin', 'organization.context', 'can:access-admin-panel'])
                        ->group(function() {
                            // Подключаем остальные файлы админки
                            require base_path('routes/api/v1/admin/users.php');
                            require base_path('routes/api/v1/admin/projects.php');
                            require base_path('routes/api/v1/admin/catalogs.php');
                            require base_path('routes/api/v1/admin/reports.php');
                            require base_path('routes/api/v1/admin/act_reports.php');
                            require base_path('routes/api/v1/admin/logs.php');
                            require base_path('routes/api/v1/admin/advance_settings.php');
                            // TODO: Добавить файл для логов аудита, когда он будет
                        });
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
                     // Добавить другие файлы лендинга...
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