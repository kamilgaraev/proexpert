<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring;

use App\BusinessModules\Features\VideoMonitoring\Contracts\StreamProvisionerInterface;
use App\BusinessModules\Features\VideoMonitoring\Services\MediaMtxStreamProvisioner;
use App\BusinessModules\Features\VideoMonitoring\Services\MediaServerManager;
use App\BusinessModules\Features\VideoMonitoring\Services\NullStreamProvisioner;
use App\BusinessModules\Features\VideoMonitoring\Services\VideoCameraService;
use Illuminate\Support\ServiceProvider;

class VideoMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VideoMonitoringModule::class);
        $this->app->singleton(StreamProvisionerInterface::class, function () {
            $config = (array) config('services.video_monitoring', []);
            $driver = (string) ($config['driver'] ?? 'none');

            return match ($driver) {
                'mediamtx' => new MediaMtxStreamProvisioner($config),
                default => new NullStreamProvisioner(),
            };
        });
        $this->app->singleton(MediaServerManager::class);
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
