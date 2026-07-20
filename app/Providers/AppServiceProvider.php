<?php

namespace App\Providers;

use App\Events\OrganizationOnboardingCompleted;
use App\Events\OrganizationProfileUpdated;
use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRemoved;
use App\Events\ProjectOrganizationRoleChanged;
use App\Listeners\InvalidateProjectContextCache;
use App\Listeners\LogProjectOrganizationActivity;
use App\Listeners\SuggestModulesBasedOnCapabilities;
use App\Models\CompletedWork;
use App\Models\MaterialReceipt;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\SystemAdmin;
// ОТКЛЮЧЕНЫ: переключились на warehouse_balances
// use App\Observers\MaterialUsageLogObserver;
use App\Models\TaskDependency;
// use App\Observers\MaterialReceiptObserver;
use App\Models\TaskResource;
use App\Modules\Core\AccessController;
use App\Modules\Core\ModuleRegistry;
use App\Modules\Core\ModuleScanner;
use App\Observers\CompletedWorkObserver;
use App\Observers\OrganizationObserver;
use App\Observers\ProjectObserver;
use App\Observers\ProjectOrganizationObserver;
use App\Observers\ProjectScheduleObserver;
use App\Observers\ScheduleTaskIntervalObserver;
use App\Observers\ScheduleTaskObserver;
use App\Observers\TaskDependencyObserver;
use App\Observers\TaskResourceObserver;
use App\Services\Export\ExcelExporterService;
use App\Services\FileService;
use App\Services\Landing\ChildOrganizationUserService;
use App\Services\RateCoefficient\RateCoefficientService;
use App\Services\Report\MaterialReportService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Contract\ContractAuditedMutationService::class, function ($app) {
            return new \App\Services\Contract\ContractAuditedMutationService(
                $app->make(\App\Services\LegalArchive\Audit\LegalDocumentAudit::class),
                $app->make('db')->connection(),
            );
        });
        $this->app->bind(\App\Services\Contract\ContractAuditReconciliationService::class, function ($app) {
            return new \App\Services\Contract\ContractAuditReconciliationService(
                $app->make('db')->connection(),
                $app->make(\App\Services\Contract\ContractAuditedMutationService::class),
                logger: $app->make(\Psr\Log\LoggerInterface::class),
            );
        });
        $this->app->bind(
            \App\Services\LegalArchive\Audit\LegalDocumentOutboxPublisher::class,
            \App\Services\LegalArchive\Audit\LaravelLegalDocumentOutboxPublisher::class,
        );
        $this->app->bind(\App\Services\LegalArchive\Audit\LegalDocumentAudit::class, function ($app) {
            $connection = $app->make('db')->connection();
            $redactor = $app->make(\App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor::class);
            $integrity = $app->make(\App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService::class);

            return new \App\Services\LegalArchive\Audit\LegalDocumentAuditService(
                new \App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder(
                    $redactor,
                    $integrity,
                    $connection,
                ),
                new \App\Services\LegalArchive\Audit\LegalDocumentOutbox(
                    redactor: $redactor,
                    integrity: $integrity,
                    connection: $connection,
                    logger: $app->make(\Psr\Log\LoggerInterface::class),
                ),
                $connection,
            );
        });

        $this->app->bind(
            \App\Services\LegalArchive\Files\LegalDocumentScanner::class,
            $this->app->environment('testing')
                ? \App\Services\LegalArchive\Files\TestingLegalDocumentScanner::class
                : \App\Services\LegalArchive\Files\FailClosedLegalDocumentScanner::class,
        );
        $this->app->bind(
            \App\Services\LegalArchive\Access\LegalDocumentAuthorizer::class,
            \App\Services\LegalArchive\Access\LegalDocumentAccessService::class,
        );
        $this->app->bind(\App\Services\LegalArchive\Signatures\ElectronicSignatureProvider::class, function ($app) {
            $driver = (string) config('legal-document-signatures.driver', 'disabled');
            $provider = config("legal-document-signatures.drivers.{$driver}");
            if (! is_string($provider) || ! is_a($provider, \App\Services\LegalArchive\Signatures\ElectronicSignatureProvider::class, true)) {
                $provider = \App\Services\LegalArchive\Signatures\DisabledElectronicSignatureProvider::class;
            }

            return $app->make($provider);
        });
        $this->app->bind(\App\Services\LegalArchive\Signatures\LegalDocumentSignatureService::class, function ($app) {
            return new \App\Services\LegalArchive\Signatures\LegalDocumentSignatureService(
                $app->make(\App\Services\LegalArchive\Signatures\ElectronicSignatureProvider::class),
                $app->make(\App\Services\LegalArchive\Access\LegalDocumentAuthorizer::class),
                $app->make(\App\Services\LegalArchive\Audit\LegalDocumentAudit::class),
                $app->make(\App\Services\Storage\FileService::class),
                $app->make('db')->connection(),
            );
        });

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

        $this->app->scoped(\App\Services\Schedule\AutoSchedulingService::class);

        // Репозиторий дашборда ЛК
        $this->app->bind(\App\Repositories\Landing\OrganizationDashboardRepositoryInterface::class, \App\Repositories\Landing\EloquentOrganizationDashboardRepository::class);

        // Здесь могут быть другие связывания

        // Доп соглашения и спецификации
        $this->app->bind(\App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface::class, \App\Repositories\SupplementaryAgreementRepository::class);
        $this->app->bind(\App\Repositories\Interfaces\SpecificationRepositoryInterface::class, \App\Repositories\SpecificationRepository::class);
        $this->app->bind(
            \App\Services\OneCExchange\Contracts\OneCExchangeOperationRepositoryInterface::class,
            \App\Services\OneCExchange\Repositories\EloquentOneCExchangeOperationRepository::class
        );
        $this->app->bind(
            \App\Services\OneCExchange\Contracts\OneCExchangeClientInterface::class,
            \App\Services\OneCExchange\Transport\HttpOneCExchangeClient::class
        );

        // Регистрируем модульную систему
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(ModuleScanner::class);
        $this->app->singleton(AccessController::class);

        // Регистрируем модули
        $this->app->register(\App\BusinessModules\Core\Organizations\OrganizationsServiceProvider::class);
        $this->app->register(\App\BusinessModules\Core\Users\UsersServiceProvider::class);
        $this->app->register(\App\BusinessModules\Core\MultiOrganization\MultiOrganizationServiceProvider::class);
        $this->app->register(\App\BusinessModules\Core\Reports\ReportsServiceProvider::class);
        $this->app->register(\App\BusinessModules\Enterprise\MultiOrganization\Reporting\ReportingServiceProvider::class);
        $this->app->register(\App\BusinessModules\Enterprise\MultiOrganization\Core\MultiOrganizationEventServiceProvider::class);
        $this->app->register(\App\BusinessModules\Addons\MaterialAnalytics\MaterialAnalyticsServiceProvider::class);
        $this->app->register(\App\BusinessModules\ContractorMarketplace\ContractorMarketplaceServiceProvider::class);

        // Регистрируем складские модули
        $this->app->register(\App\BusinessModules\Features\BasicWarehouse\BasicWarehouseServiceProvider::class);
        $this->app->register(\App\BusinessModules\Features\DesignManagement\DesignManagementServiceProvider::class);

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

        if (App::isProduction()) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Gate::define('viewApiDocs', function ($user = null) {
            return $user instanceof SystemAdmin
                && $user->hasSystemPermission('system_admin.api_docs.view');
        });

        // ОТКЛЮЧЕНЫ: переключились на warehouse_balances вместо material_balances
        // MaterialUsageLog::observe(MaterialUsageLogObserver::class);
        CompletedWork::observe(CompletedWorkObserver::class);
        // MaterialReceipt::observe(MaterialReceiptObserver::class);
        Project::observe(ProjectObserver::class);
        Organization::observe(OrganizationObserver::class);
        ProjectOrganization::observe(ProjectOrganizationObserver::class);
        \App\Models\Contract::observe(\App\Observers\ContractObserver::class);

        // Contract-related observers for non-fixed amount contracts
        \App\Models\ContractPerformanceAct::observe(\App\Observers\ContractPerformanceActObserver::class);
        \App\Models\SupplementaryAgreement::observe(\App\Observers\SupplementaryAgreementObserver::class);

        // Schedule observers
        ProjectSchedule::observe(ProjectScheduleObserver::class);
        ScheduleTask::observe(ScheduleTaskObserver::class);
        \App\Models\ScheduleTaskInterval::observe(ScheduleTaskIntervalObserver::class);
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
        // Проверяем, нужна ли синхронизация (только в production/local, не в тестах)
        if (app()->runningInConsole()) {
            return; // Пропускаем в консольных командах (запустят вручную если нужно)
        }

        try {
            // Проверяем наличие хотя бы одного системного шаблона
            $hasSystemTemplates = \App\Models\ReportTemplate::whereNull('organization_id')
                ->whereNull('user_id')
                ->whereIn('report_type', ['contractor_summary', 'contractor_detail'])
                ->exists();

            // Если системных шаблонов нет - синхронизируем автоматически при первом веб-запросе
            if (! $hasSystemTemplates) {
                $this->syncReportTemplatesFromJson();
            }

        } catch (\Illuminate\Database\QueryException $e) {
            // Если таблицы еще нет (миграции не запущены) - просто пропускаем
            // Log::debug('Skip report templates sync: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::warning('Failed to sync report templates: '.$e->getMessage());
        }
    }

    /**
     * Синхронизация шаблонов из JSON файлов.
     */
    protected function syncReportTemplatesFromJson(): void
    {
        $templatesPath = config_path('report-templates');

        if (! is_dir($templatesPath)) {
            return;
        }

        $jsonFiles = glob($templatesPath.'/*.json');

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
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Report templates synced from JSON files');
    }
}
