<?php

namespace App\BusinessModules\Features\BasicWarehouse;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class BasicWarehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BasicWarehouseModule::class);
        
        $this->registerServices();
    }

    public function boot(): void
    {
        $this->loadMigrations();
        
        $this->loadRoutes();
        
        $this->loadTranslations();
        
        $this->registerMiddleware();
    }

    protected function loadTranslations(): void
    {
        $langPath = __DIR__ . '/lang';
        
        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, 'warehouse');
        }
    }

    protected function registerServices(): void
    {
        // Сервисы будут зарегистрированы позже при создании
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

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        
        // Middleware будет создан позже
    }
}

