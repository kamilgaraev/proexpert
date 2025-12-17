<?php

namespace App\BusinessModules\Features\BudgetEstimates;

use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Features\BudgetEstimates\Services\{
    EstimateService,
    EstimateCalculationService,
    EstimateSectionService,
    EstimateItemService,
    EstimateVersionService,
    EstimateTemplateService,
    EstimateSectionNumberingService,
    EstimateItemNumberingService,
};
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\{
    EstimateProjectIntegrationService,
    EstimateContractIntegrationService,
};
use App\BusinessModules\Features\BudgetEstimates\Services\Import\{
    EstimateImportService,
    NormativeCodeService,
    NormativeMatchingService,
    ResourceMatchingService,
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
        
        // Numbering сервисы для автоматической нумерации
        $this->app->singleton(EstimateSectionNumberingService::class);
        $this->app->singleton(EstimateItemNumberingService::class);

        // Import сервисы
        $this->app->singleton(EstimateImportService::class);
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService::class);
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService::class);
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\ResourceMatchingService::class);
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
        $routesProjectPath = __DIR__ . '/routes-project.php';
        if (file_exists($routesProjectPath)) {
            require $routesProjectPath;
        }

        // Маршруты СПРАВОЧНИКОВ РЕСУРСОВ (механизмы, трудозатраты)
        $catalogsRoutesPath = __DIR__ . '/routes-catalogs.php';
        if (file_exists($catalogsRoutesPath)) {
            require $catalogsRoutesPath;
        }
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
        
        // Listeners для журнала работ (ОЖР)
        \Illuminate\Support\Facades\Event::listen(
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryApproved::class,
            \App\BusinessModules\Features\BudgetEstimates\Listeners\UpdateScheduleProgressFromJournal::class
        );
        
        \Illuminate\Support\Facades\Event::listen(
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted::class,
            \App\BusinessModules\Features\BudgetEstimates\Listeners\NotifyAboutPendingApprovals::class
        );
        
        \Illuminate\Support\Facades\Event::listen(
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalWorkVolumesRecorded::class,
            \App\BusinessModules\Features\BudgetEstimates\Listeners\UpdateEstimateActualVolumes::class
        );
    }

    /**
     * Регистрация Observers
     */
    protected function registerObservers(): void
    {
        \App\Models\Estimate::observe(\App\BusinessModules\Features\BudgetEstimates\Observers\EstimateObserver::class);
        \App\Models\EstimateSection::observe(\App\BusinessModules\Features\BudgetEstimates\Observers\EstimateSectionObserver::class);
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

