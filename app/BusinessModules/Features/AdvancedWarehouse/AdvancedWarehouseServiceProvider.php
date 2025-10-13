<?php

namespace App\BusinessModules\Features\AdvancedWarehouse;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class AdvancedWarehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdvancedWarehouseModule::class);
        
        $this->registerServices();
    }

    public function boot(): void
    {
        $this->loadMigrations();
        
        $this->loadRoutes();
        
        $this->registerMiddleware();
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

