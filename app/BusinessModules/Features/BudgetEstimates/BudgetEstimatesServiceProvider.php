<?php

namespace App\BusinessModules\Features\BudgetEstimates;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Models\EstimateItem;
use App\BusinessModules\Features\BudgetEstimates\Services\{
    EstimateService,
    EstimateCalculationService,
    EstimateSectionService,
    EstimateItemService,
    EstimateVersionService,
    EstimateTemplateService,
    EstimateSectionNumberingService,
    EstimateItemNumberingService,
    EstimateCacheService,
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
    ImportFormatOrchestrator,
};
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\{
    Generic\GenericFormatHandler,
    GrandSmeta\GrandSmetaHandler,
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
        // Регистрируем route binding для item ПЕРЕД загрузкой роутов
        $this->registerRouteBindings();
        
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
        
        // 🤖 AI-powered Import Services
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\AISectionDetector::class);
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Mapping\AIColumnMapper::class);
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Calculation\AICalculationService::class);
        
        // Регистрируем парсер с AI (если доступен)
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser::class, function ($app) {
            try {
                $aiSection = $app->make(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\AISectionDetector::class);
                $aiMapper = $app->make(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Mapping\AIColumnMapper::class);
                return new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser($aiSection, $aiMapper);
            } catch (\Exception $e) {
                // AI не доступен - возвращаем парсер без AI
                Log::warning('[BudgetEstimates] AI services not available for parser', ['error' => $e->getMessage()]);
                return new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser();
            }
        });

        // 🏗️ Modular Import Orchestration
        $this->app->singleton(ImportFormatOrchestrator::class, function ($app) {
            $handlers = [
                $app->make(GrandSmetaHandler::class),
                $app->make(GenericFormatHandler::class),
            ];
            return new ImportFormatOrchestrator($handlers);
        });

        $this->app->singleton(GenericFormatHandler::class);
        $this->app->singleton(GrandSmetaHandler::class);
        $this->app->singleton(\App\BusinessModules\Features\BudgetEstimates\Services\Import\SignatureGenerator::class);
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
     * Регистрация route bindings
     */
    protected function registerRouteBindings(): void
    {
        // Регистрируем binding для item только если он еще не зарегистрирован
        if (!Route::getBindingCallback('item')) {
            Route::bind('item', function ($value) {
                Log::info('[BudgetEstimatesServiceProvider::bind item] ===== НАЧАЛО РЕЗОЛВИНГА =====', [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => uniqid('bind_', true),
                ]);
                
                Log::info('[BudgetEstimatesServiceProvider::bind item] Начало резолвинга', [
                    'value' => $value,
                    'value_type' => gettype($value),
                    'int_value' => (int)$value,
                    'route' => request()->route()?->getName(),
                    'url' => request()->fullUrl(),
                    'method' => request()->method(),
                ]);
                
                $item = EstimateItem::withTrashed()
                    ->where('id', (int)$value)
                    ->first();
                
                Log::info('[BudgetEstimatesServiceProvider::bind item] Результат поиска', [
                    'value' => $value,
                    'item_found' => $item !== null,
                    'item_id' => $item?->id,
                    'item_estimate_id' => $item?->estimate_id,
                    'item_deleted_at' => $item?->deleted_at,
                ]);
                
                if (!$item) {
                    Log::warning('[BudgetEstimatesServiceProvider::bind item] Элемент не найден', [
                        'value' => $value,
                        'int_value' => (int)$value,
                    ]);
                    abort(404, 'Позиция сметы не найдена');
                }
                
                // Загружаем связь estimate (включая удаленные)
                $item->load(['estimate' => function ($query) {
                    $query->withTrashed();
                }]);
                
                Log::info('[BudgetEstimatesServiceProvider::bind item] После загрузки estimate', [
                    'item_id' => $item->id,
                    'estimate_loaded' => $item->relationLoaded('estimate'),
                    'estimate_exists' => $item->estimate !== null,
                    'estimate_id' => $item->estimate?->id,
                    'estimate_organization_id' => $item->estimate?->organization_id,
                    'estimate_deleted_at' => $item->estimate?->deleted_at,
                ]);
                
                $user = request()->user();
                Log::info('[BudgetEstimatesServiceProvider::bind item] Информация о пользователе', [
                    'user_exists' => $user !== null,
                    'user_id' => $user?->id,
                    'current_organization_id' => $user?->current_organization_id,
                ]);
                
                if ($user && $user->current_organization_id) {
                    // Если estimate не найден, возвращаем 404
                    if (!$item->estimate) {
                        Log::warning('[BudgetEstimatesServiceProvider::bind item] Estimate не найден для элемента', [
                            'item_id' => $item->id,
                            'item_estimate_id' => $item->estimate_id,
                        ]);
                        abort(404, 'Смета для этой позиции не найдена');
                    }
                    
                    // Проверяем организацию
                    $itemOrgId = (int)$item->estimate->organization_id;
                    $userOrgId = (int)$user->current_organization_id;
                    
                    Log::info('[BudgetEstimatesServiceProvider::bind item] Проверка организации', [
                        'item_id' => $item->id,
                        'estimate_id' => $item->estimate->id,
                        'item_organization_id' => $itemOrgId,
                        'user_organization_id' => $userOrgId,
                        'match' => $itemOrgId === $userOrgId,
                    ]);
                    
                    if ($itemOrgId !== $userOrgId) {
                        Log::warning('[BudgetEstimatesServiceProvider::bind item] Организация не совпадает', [
                            'item_id' => $item->id,
                            'item_organization_id' => $itemOrgId,
                            'user_organization_id' => $userOrgId,
                        ]);
                        abort(403, 'У вас нет доступа к этой позиции сметы');
                    }
                }
                
                Log::info('[BudgetEstimatesServiceProvider::bind item] Успешное резолвинг', [
                    'item_id' => $item->id,
                    'estimate_id' => $item->estimate?->id,
                ]);
                
                return $item;
            });
        }
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
        // Миграции модуля теперь загружаются из локальной папки модуля
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');
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

