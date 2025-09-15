<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use App\Services\Organization\OrganizationContext;
use App\Services\FileService;
use App\Services\Export\ExcelExporterService;
use App\Services\Report\MaterialReportService;
use App\Services\Landing\ChildOrganizationUserService;
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

        // Регистрируем ChildOrganizationUserService с новыми зависимостями
        $this->app->singleton(ChildOrganizationUserService::class, function ($app) {
            return new ChildOrganizationUserService(
                $app->make(\App\Domain\Authorization\Services\CustomRoleService::class),
                $app->make(\App\Domain\Authorization\Services\AuthorizationService::class),
                $app->make(\App\Services\UserInvitationService::class)
            );
        });
        
        // Регистрируем UserService с AuthorizationService
        $this->app->singleton(\App\Services\User\UserService::class, function ($app) {
            return new \App\Services\User\UserService(
                $app->make(\App\Repositories\Interfaces\UserRepositoryInterface::class),
                $app->make(\App\Domain\Authorization\Services\AuthorizationService::class)
            );
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
        $this->app->register(\App\BusinessModules\Core\Organizations\OrganizationsServiceProvider::class);
        $this->app->register(\App\BusinessModules\Core\Users\UsersServiceProvider::class);
        $this->app->register(\App\BusinessModules\Core\MultiOrganization\MultiOrganizationServiceProvider::class);
        $this->app->register(\App\BusinessModules\Features\BasicReports\BasicReportsServiceProvider::class);
        $this->app->register(\App\BusinessModules\Features\AdvancedReports\AdvancedReportsServiceProvider::class);
        $this->app->register(\App\BusinessModules\Enterprise\MultiOrganization\Reporting\ReportingServiceProvider::class);
        $this->app->register(\App\BusinessModules\Enterprise\MultiOrganization\Core\MultiOrganizationEventServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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