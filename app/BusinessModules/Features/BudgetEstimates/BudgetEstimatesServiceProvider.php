<?php

namespace App\BusinessModules\Features\BudgetEstimates;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\BudgetEstimates\Services\{
    EstimateService,
    EstimateCalculationService,
    EstimateSectionService,
    EstimateItemService,
    EstimateVersionService,
    EstimateTemplateService,
};
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\{
    EstimateProjectIntegrationService,
    EstimateContractIntegrationService,
};
use App\BusinessModules\Features\BudgetEstimates\Services\Import\{
    EstimateImportService,
    WorkTypeMatchingService,
};
use App\Repositories\{
    EstimateRepository,
    EstimateItemRepository,
    EstimateSectionRepository,
    EstimateTemplateRepository,
};

class BudgetEstimatesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Регистрация основного модуля
        $this->app->singleton(BudgetEstimatesModule::class);

        // Регистрация репозиториев
        $this->registerRepositories();

        // Регистрация сервисов
        $this->registerServices();

        // Регистрация интеграционных сервисов
        $this->registerIntegrationServices();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Загрузка маршрутов
        $this->loadRoutes();

        // Загрузка миграций
        $this->loadMigrations();

        // Регистрация Events и Listeners
        $this->registerEvents();

        // Регистрация Observers
        $this->registerObservers();

        // Регистрация middleware
        $this->registerMiddleware();

        // Публикация конфигов (опционально)
        $this->publishes([
            __DIR__.'/config/budget-estimates.php' => config_path('budget-estimates.php'),
        ], 'budget-estimates-config');
    }

    /**
     * Регистрация репозиториев
     */
    protected function registerRepositories(): void
    {
        $this->app->singleton(EstimateRepository::class);
        $this->app->singleton(EstimateItemRepository::class);
        $this->app->singleton(EstimateSectionRepository::class);
        $this->app->singleton(EstimateTemplateRepository::class);
    }

    /**
     * Регистрация основных сервисов
     */
    protected function registerServices(): void
    {
        // Cache сервис (регистрируем первым, так как другие сервисы зависят от него)
        $this->app->singleton(EstimateCacheService::class);
        
        // Core сервисы
        $this->app->singleton(EstimateService::class);
        $this->app->singleton(EstimateCalculationService::class);
        $this->app->singleton(EstimateSectionService::class);
        $this->app->singleton(EstimateItemService::class);
        $this->app->singleton(EstimateVersionService::class);
        $this->app->singleton(EstimateTemplateService::class);

        // Import сервисы
        $this->app->singleton(EstimateImportService::class);
        $this->app->singleton(WorkTypeMatchingService::class);
    }

    /**
     * Регистрация интеграционных сервисов
     */
    protected function registerIntegrationServices(): void
    {
        $this->app->singleton(EstimateProjectIntegrationService::class);
        $this->app->singleton(EstimateContractIntegrationService::class);
    }

    /**
     * Загрузка маршрутов
     */
    protected function loadRoutes(): void
    {
        // Маршруты БЕЗ контекста проекта (настройки, версии, шаблоны)
        $routesPath = __DIR__ . '/routes.php';
        if (file_exists($routesPath)) {
            require $routesPath;
        }
        
        // Маршруты В КОНТЕКСТЕ проекта (estimates CRUD, sections, items)
        $this->loadProjectBasedRoutes();
    }
    
    /**
     * Загрузка маршрутов в контексте проекта
     */
    protected function loadProjectBasedRoutes(): void
    {
        Route::middleware(['api', 'auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'authorize:admin.access', 'interface:admin', 'project.context'])
            ->prefix('api/v1/admin/projects/{project}')
            ->group(function () {
                Route::middleware(['budget-estimates.active'])
                    ->prefix('estimates')
                    ->group(function () {
                        Route::get('/', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'index']);
                        Route::post('/', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'store']);
                        Route::get('/{estimate}', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'show']);
                        Route::put('/{estimate}', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'update']);
                        Route::delete('/{estimate}', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'destroy']);
                        
                        Route::post('/{estimate}/duplicate', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'duplicate']);
                        Route::post('/{estimate}/recalculate', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'recalculate']);
                        Route::get('/{estimate}/dashboard', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'dashboard']);
                        Route::get('/{estimate}/structure', [\App\Http\Controllers\Api\V1\Admin\EstimateController::class, 'structure']);
                        
                        Route::prefix('{estimate}/sections')->group(function () {
                            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\EstimateSectionController::class, 'index']);
                            Route::post('/', [\App\Http\Controllers\Api\V1\Admin\EstimateSectionController::class, 'store']);
                        });
                        
                        Route::prefix('{estimate}/items')->group(function () {
                            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\EstimateItemController::class, 'index']);
                            Route::post('/', [\App\Http\Controllers\Api\V1\Admin\EstimateItemController::class, 'store']);
                            Route::post('/bulk', [\App\Http\Controllers\Api\V1\Admin\EstimateItemController::class, 'bulkStore']);
                        });
                        
                        Route::prefix('import')->name('estimates.import.')->group(function () {
                            Route::post('/upload', [\App\Http\Controllers\Api\V1\Admin\EstimateImportController::class, 'upload'])->name('upload');
                            Route::post('/detect', [\App\Http\Controllers\Api\V1\Admin\EstimateImportController::class, 'detect'])->name('detect');
                            Route::post('/map', [\App\Http\Controllers\Api\V1\Admin\EstimateImportController::class, 'map'])->name('map');
                            Route::post('/match', [\App\Http\Controllers\Api\V1\Admin\EstimateImportController::class, 'match'])->name('match');
                            Route::post('/execute', [\App\Http\Controllers\Api\V1\Admin\EstimateImportController::class, 'execute'])->name('execute');
                            Route::get('/status/{jobId}', [\App\Http\Controllers\Api\V1\Admin\EstimateImportController::class, 'status'])->name('status');
                            Route::get('/history', [\App\Http\Controllers\Api\V1\Admin\EstimateImportController::class, 'history'])->name('history');
                        });
                    });
            });
    }

    /**
     * Загрузка миграций
     */
    protected function loadMigrations(): void
    {
        $migrationsPath = database_path('migrations');

        // Миграции модуля находятся в основной папке migrations
        // Они уже загружаются автоматически Laravel
        // Если нужно, можно добавить свою папку:
        // $this->loadMigrationsFrom(__DIR__ . '/migrations');
    }

    /**
     * Регистрация событий и слушателей
     */
    protected function registerEvents(): void
    {
        // События будут зарегистрированы через EventServiceProvider
        // Или можно зарегистрировать здесь напрямую:
        
        // Event::listen(
        //     \App\BusinessModules\Features\BudgetEstimates\Events\EstimateCreated::class,
        //     \App\BusinessModules\Features\BudgetEstimates\Listeners\UpdateProjectBudget::class
        // );
    }

    /**
     * Регистрация Observers
     */
    protected function registerObservers(): void
    {
        \App\Models\Estimate::observe(\App\BusinessModules\Features\BudgetEstimates\Observers\EstimateObserver::class);
    }

    /**
     * Регистрация middleware
     */
    protected function registerMiddleware(): void
    {
        // Middleware для проверки активации модуля
        $router = $this->app['router'];
        
        $router->aliasMiddleware(
            'budget-estimates.active',
            \App\BusinessModules\Features\BudgetEstimates\Http\Middleware\EnsureBudgetEstimatesActive::class
        );
    }
}

