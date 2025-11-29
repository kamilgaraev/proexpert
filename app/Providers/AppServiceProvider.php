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
use App\Models\Project;
use App\Models\Organization;
use App\Models\ProjectOrganization;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Models\TaskResource;
// ОТКЛЮЧЕНЫ: переключились на warehouse_balances
// use App\Observers\MaterialUsageLogObserver;
use App\Observers\CompletedWorkObserver;
// use App\Observers\MaterialReceiptObserver;
use App\Observers\ProjectObserver;
use App\Observers\OrganizationObserver;
use App\Observers\ProjectOrganizationObserver;
use App\Observers\ScheduleTaskObserver;
use App\Observers\TaskDependencyObserver;
use App\Observers\TaskResourceObserver;
use Illuminate\Support\Facades\Event;
use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRoleChanged;
use App\Events\ProjectOrganizationRemoved;
use App\Events\OrganizationProfileUpdated;
use App\Events\OrganizationOnboardingCompleted;
use App\Listeners\LogProjectOrganizationActivity;
use App\Listeners\InvalidateProjectContextCache;
use App\Listeners\SuggestModulesBasedOnCapabilities;
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
            return new ExcelExporterService($app->make(\App\Services\Logging\LoggingService::class));
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
                $app->make(\App\Domain\Authorization\Services\AuthorizationService::class),
                $app->make(\App\Helpers\AdminPanelAccessHelper::class),
                $app->make(\App\Services\Logging\LoggingService::class)
            );
        });

        // Регистрируем OrganizationSubscriptionService
        $this->app->singleton(\App\Services\Landing\OrganizationSubscriptionService::class, function ($app) {
            return new \App\Services\Landing\OrganizationSubscriptionService(
                $app->make(\App\Services\Logging\LoggingService::class),
                $app->make(\App\Services\SubscriptionModuleSyncService::class),
                $app->make(\App\Services\Billing\SubscriptionLimitsService::class)
            );
        });

        // Регистрируем OrganizationDashboardService
        $this->app->singleton(\App\Services\Landing\OrganizationDashboardService::class, function ($app) {
            return new \App\Services\Landing\OrganizationDashboardService(
                $app->make(\App\Services\Landing\OrganizationSubscriptionService::class)
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
        $this->app->register(\App\BusinessModules\Features\AdvancedDashboard\AdvancedDashboardServiceProvider::class);
        $this->app->register(\App\BusinessModules\Enterprise\MultiOrganization\Reporting\ReportingServiceProvider::class);
        $this->app->register(\App\BusinessModules\Enterprise\MultiOrganization\Core\MultiOrganizationEventServiceProvider::class);
        $this->app->register(\App\BusinessModules\Addons\MaterialAnalytics\MaterialAnalyticsServiceProvider::class);
        
        // Регистрируем складские модули
        $this->app->register(\App\BusinessModules\Features\BasicWarehouse\BasicWarehouseServiceProvider::class);
        $this->app->register(\App\BusinessModules\Features\AdvancedWarehouse\AdvancedWarehouseServiceProvider::class);
        
        // Error Tracking Services
        $this->app->singleton(\App\Services\ErrorTracking\ErrorTrackingService::class);
        $this->app->singleton(\App\Services\ErrorTracking\ErrorTrackingServiceAsync::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Автоматическая синхронизация системных шаблонов отчетов при первом запуске
        $this->syncReportTemplatesOnBoot();
        
        // ОТКЛЮЧЕНЫ: переключились на warehouse_balances вместо material_balances
        // MaterialUsageLog::observe(MaterialUsageLogObserver::class);
        CompletedWork::observe(CompletedWorkObserver::class);
        // MaterialReceipt::observe(MaterialReceiptObserver::class);
        Project::observe(ProjectObserver::class);
        Organization::observe(OrganizationObserver::class);
        ProjectOrganization::observe(ProjectOrganizationObserver::class);
        \App\Models\OrganizationModuleActivation::observe(\App\Observers\OrganizationModuleActivationObserver::class);
        \App\Models\Contract::observe(\App\Observers\ContractObserver::class);
        
        // Contract-related observers for non-fixed amount contracts
        \App\Models\ContractPerformanceAct::observe(\App\Observers\ContractPerformanceActObserver::class);
        \App\Models\SupplementaryAgreement::observe(\App\Observers\SupplementaryAgreementObserver::class);
        
        // Schedule observers
        ScheduleTask::observe(ScheduleTaskObserver::class);
        TaskDependency::observe(TaskDependencyObserver::class);
        TaskResource::observe(TaskResourceObserver::class);
        
        // Estimate Position Catalog Observer
        \App\Models\EstimatePositionCatalog::observe(\App\Observers\EstimatePositionCatalogObserver::class);
        
        // Project-Based RBAC Events
        Event::listen(ProjectOrganizationAdded::class, [LogProjectOrganizationActivity::class, 'handleAdded']);
        Event::listen(ProjectOrganizationAdded::class, [InvalidateProjectContextCache::class, 'handleAdded']);
        
        Event::listen(ProjectOrganizationRoleChanged::class, [LogProjectOrganizationActivity::class, 'handleRoleChanged']);
        Event::listen(ProjectOrganizationRoleChanged::class, [InvalidateProjectContextCache::class, 'handleRoleChanged']);
        
        Event::listen(ProjectOrganizationRemoved::class, [LogProjectOrganizationActivity::class, 'handleRemoved']);
        Event::listen(ProjectOrganizationRemoved::class, [InvalidateProjectContextCache::class, 'handleRemoved']);
        
        // Organization Profile Events
        Event::listen(OrganizationProfileUpdated::class, [SuggestModulesBasedOnCapabilities::class, 'handleProfileUpdated']);
        
        Event::listen(OrganizationOnboardingCompleted::class, [SuggestModulesBasedOnCapabilities::class, 'handleOnboardingCompleted']);
    }

    /**
     * Синхронизация системных шаблонов отчетов при первом запуске.
     */
    protected function syncReportTemplatesOnBoot(): void
    {
        // Проверяем, нужна ли синхронизация (только в production/local, не в тестах)
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return; // Пропускаем в консольных командах (запустят вручную если нужно)
        }

        try {
            // Проверяем наличие хотя бы одного системного шаблона
            $hasSystemTemplates = \App\Models\ReportTemplate::whereNull('organization_id')
                ->whereNull('user_id')
                ->whereIn('report_type', ['contractor_summary', 'contractor_detail'])
                ->exists();

            // Если системных шаблонов нет - синхронизируем автоматически при первом веб-запросе
            if (!$hasSystemTemplates) {
                $this->syncReportTemplatesFromJson();
            }
        } catch (\Exception $e) {
            // Если таблицы еще нет (миграции не запущены) - просто пропускаем
            Log::debug('Skip report templates sync: ' . $e->getMessage());
        }
    }

    /**
     * Синхронизация шаблонов из JSON файлов.
     */
    protected function syncReportTemplatesFromJson(): void
    {
        $templatesPath = config_path('report-templates');
        
        if (!is_dir($templatesPath)) {
            return;
        }

        $jsonFiles = glob($templatesPath . '/*.json');

        foreach ($jsonFiles as $jsonFile) {
            try {
                $templates = json_decode(file_get_contents($jsonFile), true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                foreach ($templates as $templateData) {
                    // Создаем только если не существует
                    \App\Models\ReportTemplate::firstOrCreate(
                        [
                            'report_type' => $templateData['report_type'],
                            'name' => $templateData['name'],
                            'organization_id' => null,
                            'user_id' => null,
                        ],
                        [
                            'is_default' => $templateData['is_default'] ?? false,
                            'columns_config' => $templateData['columns_config'],
                        ]
                    );
                }
            } catch (\Exception $e) {
                Log::warning('Failed to sync report template from JSON', [
                    'file' => basename($jsonFile),
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Report templates synced from JSON files');
    }
} 