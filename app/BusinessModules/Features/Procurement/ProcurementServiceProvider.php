<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider для модуля "Управление закупками"
 */
class ProcurementServiceProvider extends ServiceProvider
{
    /**
     * Регистрация сервисов
     */
    public function register(): void
    {
        // Регистрируем основной модуль как singleton
        $this->app->singleton(ProcurementModule::class);

        // Регистрируем сервисы модуля
        $this->registerServices();
    }

    /**
     * Загрузка модуля
     */
    public function boot(): void
    {
        $this->app->afterResolving(ReportDefinitionBindingAssembler::class, function (ReportDefinitionBindingAssembler $assembler): void {
            $this->app->make(Reporting\Cycle\ProcurementCyclePublishedRuntimeBindingRegistrar::class)->register($assembler);
            $this->app->make(Reporting\Award\SupplierAwardPublishedRuntimeBindingRegistrar::class)->register($assembler);
            $this->app->make(Reporting\Supply\SupplyReliabilityPublishedRuntimeBindingRegistrar::class)->register($assembler);
        });

        // Загружаем миграции
        $this->loadMigrations();

        // Загружаем маршруты
        $this->loadRoutes();

        // Регистрируем middleware
        $this->registerMiddleware();

        // Регистрируем события и слушателей
        $this->registerEvents();

        // Регистрируем observers
        $this->registerObservers();
    }

    /**
     * Регистрация сервисов
     */
    protected function registerServices(): void
    {
        $this->app->singleton(
            Contracts\PurchaseReceiptReturnAuthorizer::class,
            Services\PurchaseReceiptReturnAccessResolver::class,
        );
        $this->app->singleton(
            Contracts\PurchaseReceiptReturnUnitOfWork::class,
            Services\DatabasePurchaseReceiptReturnUnitOfWork::class,
        );

        $this->app->singleton(
            Reporting\Cycle\Contracts\ProcurementCycleSourceReader::class,
            Reporting\Cycle\Services\EloquentProcurementCycleSourceReader::class,
        );

        $this->app->singleton(
            Reporting\Cycle\Contracts\ProcurementCycleSourceSnapshotWriter::class,
            Reporting\Cycle\Services\CanonicalProcurementCycleSourceSnapshotWriter::class,
        );

        $this->app->singleton(Reporting\Cycle\Services\ProcurementCycleFormula::class);
        $this->app->singleton(Reporting\Cycle\Services\ProcurementCycleSourceSnapshotMaterializer::class);
        $this->app->singleton(Reporting\Cycle\Services\ProcurementCycleReportAdapter::class);
        $this->app->singleton(Reporting\Cycle\Services\ProcurementCycleReadinessProbe::class);
        $this->app->singleton(Reporting\Cycle\Services\ProcurementCycleReportBindingFactory::class);
        $this->app->singleton(Reporting\Cycle\ProcurementCyclePublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(Reporting\Award\Services\SupplierAwardFormula::class);
        $this->app->singleton(Reporting\Award\Services\SupplierProposalComparabilityPolicy::class);
        $this->app->singleton(Reporting\Award\Services\ComparableProposalVersionFactory::class);
        $this->app->singleton(Reporting\Award\Queries\SupplierAwardFilteredUniverse::class);
        $this->app->singleton(Reporting\Award\Services\SupplierAwardSnapshotMaterializer::class);
        $this->app->singleton(Reporting\Award\Providers\SupplierAwardCompetitivenessReportProvider::class);
        $this->app->singleton(Reporting\Award\Queries\SupplierAwardRowQuery::class);
        $this->app->singleton(Reporting\Award\Readiness\SupplierAwardReadinessProbe::class);
        $this->app->singleton(Reporting\Award\Services\SupplierAwardReportBindingFactory::class);
        $this->app->singleton(Reporting\Award\SupplierAwardPublishedRuntimeBindingRegistrar::class);
        $this->app->singleton(Reporting\Supply\Services\SupplyReliabilityPeriod::class);
        $this->app->singleton(Reporting\Supply\Services\SupplyReliabilityFormula::class);
        $this->app->singleton(Reporting\Supply\Services\SupplyReliabilitySnapshotMaterializer::class);
        $this->app->singleton(Reporting\Supply\Providers\SupplyReliabilityReportProvider::class);
        $this->app->singleton(Reporting\Supply\Queries\SupplyReliabilityRowQuery::class);
        $this->app->singleton(Reporting\Supply\DrillDown\SupplyReliabilityDrillDownProvider::class);
        $this->app->singleton(Reporting\Supply\Readiness\SupplyReliabilityReadinessProbe::class);
        $this->app->singleton(Reporting\Supply\Services\SupplyReliabilityReportBindingFactory::class);
        $this->app->singleton(Reporting\Supply\SupplyReliabilityPublishedRuntimeBindingRegistrar::class);

        $this->app->singleton(
            Reporting\Cycle\Contracts\ProcurementProcessEventStore::class,
            Reporting\Cycle\Services\EloquentProcurementProcessEventStore::class,
        );

        $this->app->singleton(
            Reporting\Cycle\Contracts\ProcurementTransactionBoundary::class,
            Reporting\Cycle\Services\LaravelProcurementTransactionBoundary::class,
        );

        $this->app->singleton(
            Reporting\Cycle\Contracts\ProcurementOwnerWorkflowRuntime::class,
            Reporting\Cycle\Services\LaravelProcurementOwnerWorkflowRuntime::class,
        );

        $this->app->singleton(
            Reporting\Cycle\Contracts\ProcurementCycleSourceState::class,
            Reporting\Cycle\Services\EloquentProcurementCycleSourceState::class,
        );

        $this->app->singleton(
            Reporting\Award\Contracts\ProcurementAwardEvidenceStore::class,
            Reporting\Award\Services\EloquentProcurementAwardEvidenceStore::class,
        );

        $this->app->singleton(
            Reporting\Award\Contracts\ProcurementAwardSelectionSource::class,
            Reporting\Award\Services\EloquentProcurementAwardSelectionSource::class,
        );

        $this->app->singleton(
            Reporting\Award\Contracts\ProcurementAwardOwnerEventWriter::class,
            Reporting\Award\Services\ProcurementAwardOwnerEventRecorder::class,
        );

        // Основные сервисы модуля
        $this->app->singleton(
            Services\PurchaseRequestService::class
        );

        $this->app->singleton(
            Services\PurchaseOrderService::class
        );

        $this->app->singleton(
            Services\SupplierProposalService::class
        );

        $this->app->singleton(
            Services\ProcurementApprovalService::class
        );

        $this->app->singleton(
            Services\ProcurementApprovalPolicyService::class
        );

        $this->app->singleton(
            Services\ProcurementDutySeparationService::class
        );

        $this->app->singleton(
            Services\ProcurementAuditService::class
        );

        $this->app->singleton(
            Services\SupplierProposalComparisonService::class
        );

        $this->app->singleton(
            Services\SupplierSelectionService::class
        );

        $this->app->singleton(
            Services\SupplierRequestVersionService::class
        );

        $this->app->singleton(
            Services\PurchaseContractService::class
        );

        $this->app->singleton(
            Services\CatalogIntegrationService::class
        );

        $this->app->singleton(
            Services\PurchaseOrderPdfService::class
        );

        $this->app->scoped(
            Services\MobileProcurementService::class
        );
    }

    /**
     * Загрузка миграций
     */
    protected function loadMigrations(): void
    {
        $migrationsPath = __DIR__.'/migrations';

        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    /**
     * Загрузка маршрутов
     */
    protected function loadRoutes(): void
    {
        $routesPath = __DIR__.'/routes.php';

        if (file_exists($routesPath)) {
            require $routesPath;
        }

        $mobileRoutesPath = __DIR__.'/routes-mobile.php';

        if (file_exists($mobileRoutesPath)) {
            require $mobileRoutesPath;
        }
    }

    /**
     * Регистрация middleware
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware(
            'procurement.active',
            Http\Middleware\EnsureProcurementActive::class
        );
    }

    /**
     * Регистрация событий и слушателей
     */
    protected function registerEvents(): void
    {
        // Создание заявки на закупку из заявки с объекта
        Event::listen(
            \App\BusinessModules\Features\SiteRequests\Events\SiteRequestApproved::class,
            Listeners\CreatePurchaseRequestFromSiteRequest::class
        );

        // Создание счета при создании заказа
        Event::listen(
            Events\PurchaseOrderCreated::class,
            Listeners\CreateInvoiceFromPurchaseOrder::class
        );

        // Обновление склада при получении материалов
        Event::listen(
            Events\MaterialReceivedFromSupplier::class,
            Listeners\UpdateWarehouseOnMaterialReceipt::class
        );

        // Уведомления
        Event::listen(
            Events\PurchaseRequestCreated::class,
            [Listeners\SendProcurementNotifications::class, 'handleRequestCreated']
        );

        Event::listen(
            Events\PurchaseRequestApproved::class,
            [Listeners\SendProcurementNotifications::class, 'handleRequestApproved']
        );

        Event::listen(
            Events\PurchaseOrderSent::class,
            [Listeners\SendProcurementNotifications::class, 'handleOrderSent']
        );

        Event::listen(
            Events\MaterialReceivedFromSupplier::class,
            [Listeners\SendProcurementNotifications::class, 'handleMaterialsReceived']
        );
    }

    /**
     * Регистрация observers
     */
    protected function registerObservers(): void
    {
        // Регистрируем audit observer для всех моделей закупок
        Models\PurchaseRequest::observe(Observers\ProcurementAuditObserver::class);
        Models\PurchaseOrder::observe(Observers\ProcurementAuditObserver::class);
        Models\SupplierProposal::observe(Observers\ProcurementAuditObserver::class);
    }
}
