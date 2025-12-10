<?php

namespace App\BusinessModules\Features\Procurement;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

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
            Services\SupplierSelectionService::class
        );

        $this->app->singleton(
            Services\PurchaseContractService::class
        );

        $this->app->singleton(
            Services\CatalogIntegrationService::class
        );
    }

    /**
     * Загрузка миграций
     */
    protected function loadMigrations(): void
    {
        $migrationsPath = __DIR__ . '/migrations';

        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    /**
     * Загрузка маршрутов
     */
    protected function loadRoutes(): void
    {
        $routesPath = __DIR__ . '/routes.php';

        if (file_exists($routesPath)) {
            require $routesPath;
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

