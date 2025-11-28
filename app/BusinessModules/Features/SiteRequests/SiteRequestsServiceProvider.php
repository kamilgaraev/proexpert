<?php

namespace App\BusinessModules\Features\SiteRequests;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Service Provider для модуля "Заявки с объекта"
 */
class SiteRequestsServiceProvider extends ServiceProvider
{
    /**
     * Регистрация сервисов
     */
    public function register(): void
    {
        // Регистрируем основной модуль как singleton
        $this->app->singleton(SiteRequestsModule::class);

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
        // Основной сервис заявок
        $this->app->singleton(
            Services\SiteRequestService::class
        );

        // Сервис workflow
        $this->app->singleton(
            Services\SiteRequestWorkflowService::class
        );

        // Сервис уведомлений
        $this->app->singleton(
            Services\SiteRequestNotificationService::class
        );

        // Сервис шаблонов
        $this->app->singleton(
            Services\SiteRequestTemplateService::class
        );

        // Сервис календаря
        $this->app->singleton(
            Services\SiteRequestCalendarService::class
        );

        // Сервис создания платежей из заявок
        $this->app->singleton(
            Services\SiteRequestPaymentService::class
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
            'site_requests.active',
            Http\Middleware\CheckSiteRequestsModuleActive::class
        );
    }

    /**
     * Регистрация событий и слушателей
     */
    protected function registerEvents(): void
    {
        // Отправка уведомлений при создании заявки
        Event::listen(
            Events\SiteRequestCreated::class,
            Listeners\SendSiteRequestNotification::class
        );

        // Создание события в календаре при создании заявки
        Event::listen(
            Events\SiteRequestCreated::class,
            Listeners\CreateCalendarEventOnSiteRequest::class
        );

        // Обновление события в календаре при изменении заявки
        Event::listen(
            Events\SiteRequestUpdated::class,
            Listeners\UpdateCalendarEventOnSiteRequest::class
        );

        // Уведомление о смене статуса
        Event::listen(
            Events\SiteRequestStatusChanged::class,
            Listeners\SendStatusChangeNotification::class
        );

        // Уведомление о назначении исполнителя
        Event::listen(
            Events\SiteRequestAssigned::class,
            Listeners\SendAssignmentNotification::class
        );
    }

    /**
     * Регистрация observers
     */
    protected function registerObservers(): void
    {
        Models\SiteRequest::observe(Observers\SiteRequestObserver::class);
    }
}

