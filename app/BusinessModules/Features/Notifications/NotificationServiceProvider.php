<?php

namespace App\BusinessModules\Features\Notifications;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationModule::class);
        
        $this->registerServices();
    }

    public function boot(): void
    {
        $this->loadMigrations();
        
        $this->loadRoutes();
        
        $this->registerEvents();
        
        $this->publishes([
            __DIR__.'/config/notifications.php' => config_path('notifications.php'),
        ], 'notifications-config');
    }

    protected function registerServices(): void
    {
        $this->app->singleton(
            \App\BusinessModules\Features\Notifications\Services\NotificationService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\Notifications\Services\TemplateRenderer::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\Notifications\Services\PreferenceManager::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\Notifications\Services\AnalyticsService::class
        );
    }

    protected function loadRoutes(): void
    {
        $routesPath = __DIR__ . '/routes.php';
        
        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }

    protected function loadMigrations(): void
    {
        $migrationsPath = __DIR__ . '/migrations';
        
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    protected function registerEvents(): void
    {
        \App\BusinessModules\Features\Notifications\Integration\ContractEventIntegration::register();
    }
}

