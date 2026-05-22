<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking;

use App\BusinessModules\Features\TimeTracking\Http\Middleware\EnsureTimeTrackingActive;
use App\BusinessModules\Features\TimeTracking\Services\MobileTimeTrackingService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TimeTrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TimeTrackingModule::class);
        $this->app->scoped(MobileTimeTrackingService::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('time-tracking.active', EnsureTimeTrackingActive::class);

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }
}
