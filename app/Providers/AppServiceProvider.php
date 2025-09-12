<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Routing\Router;
use App\Services\Organization\OrganizationContext;
use App\Services\FileService;
use App\Services\Export\ExcelExporterService;
use App\Services\Report\MaterialReportService;
use App\Services\Landing\ChildOrganizationUserService;
use App\Services\OrganizationRoleService;
use App\Services\RateCoefficient\RateCoefficientService;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\CompletedWork;
use App\Models\MaterialReceipt;
use App\Observers\MaterialUsageLogObserver;
use App\Observers\CompletedWorkObserver;
use App\Observers\MaterialReceiptObserver;
use App\Modules\Core\ModuleScanner;
use App\Modules\Core\ModuleRegistry;
use App\Modules\Core\BillingEngine;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Log;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Регистрируем FileService как singleton
        $this->app->singleton(FileService::class, function ($app) {
            return new FileService($app->make(\Illuminate\Contracts\Filesystem\Factory::class));
        });
        // Регистрируем ExcelExporterService как singleton
        $this->app->singleton(ExcelExporterService::class, function ($app) {
            return new ExcelExporterService();
        });
        
        // Регистрируем MaterialReportService как singleton
        $this->app->singleton(MaterialReportService::class, function ($app) {
            return new MaterialReportService($app->make(RateCoefficientService::class));
        });

        $this->app->singleton(ChildOrganizationUserService::class, function ($app) {
            return new ChildOrganizationUserService($app->make(OrganizationRoleService::class));
        });
        
        // Репозиторий дашборда ЛК
        $this->app->bind(\App\Repositories\Landing\OrganizationDashboardRepositoryInterface::class, \App\Repositories\Landing\EloquentOrganizationDashboardRepository::class);

        // Здесь могут быть другие связывания

        // Доп соглашения и спецификации
        $this->app->bind(\App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface::class, \App\Repositories\SupplementaryAgreementRepository::class);
        $this->app->bind(\App\Repositories\Interfaces\SpecificationRepositoryInterface::class, \App\Repositories\SpecificationRepository::class);
        
        // Регистрируем модульную систему
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(ModuleScanner::class);
        $this->app->singleton(BillingEngine::class);
        $this->app->singleton(AccessController::class);
        
        // Регистрируем модули
        $this->app->register(\App\BusinessModules\Core\MultiOrganization\MultiOrganizationServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Регистрируем CORS middleware
        $router = $this->app->make(Router::class);
        
        // Добавляем middleware в группы
        $router->pushMiddlewareToGroup("web", CorsMiddleware::class);
        $router->pushMiddlewareToGroup("api", CorsMiddleware::class);
        
        // Добавляем его первым в группе api
        if (method_exists($router, "prependToGroup")) {
            $router->prependToGroup("api", CorsMiddleware::class);
        } else {
            $router->prependMiddlewareToGroup("api", CorsMiddleware::class);
        }
        
        // Регистрируем observers для автоматической синхронизации данных
        MaterialUsageLog::observe(MaterialUsageLogObserver::class);
        CompletedWork::observe(CompletedWorkObserver::class);
        MaterialReceipt::observe(MaterialReceiptObserver::class);
        
        // Автоматическое сканирование и регистрация модулей
        // Выполняем только если не в консоли или во время тестов
        if (!$this->app->runningInConsole() || $this->app->runningUnitTests()) {
            try {
                $moduleScanner = $this->app->make(ModuleScanner::class);
                $moduleScanner->scanAndRegister();
            } catch (\Exception $e) {
                // Логируем ошибку, но не падаем
                Log::warning('Module auto-registration failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }
} 