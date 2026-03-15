<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring;

use App\BusinessModules\Features\VideoMonitoring\Services\VideoCameraService;
use Illuminate\Support\ServiceProvider;

class VideoMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VideoMonitoringModule::class);
        $this->app->singleton(VideoCameraService::class);
    }

    public function boot(): void
    {
        $this->loadMigrations();
        $this->loadRoutes();
    }

    protected function loadMigrations(): void
    {
        $migrationsPath = __DIR__ . '/migrations';

        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    protected function loadRoutes(): void
    {
        $routesPath = __DIR__ . '/routes.php';

        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }
}
