<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking;

use App\BusinessModules\Features\TimeTracking\Http\Middleware\EnsureTimeTrackingActive;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\EffectiveLaborRateSource;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\ProjectLaborCostDatabasePort;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use App\BusinessModules\Features\TimeTracking\Reporting\Infrastructure\DatabaseProjectLaborCostAdapter;
use App\BusinessModules\Features\TimeTracking\Services\MobileTimeTrackingService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TimeTrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TimeTrackingModule::class);
        $this->app->scoped(MobileTimeTrackingService::class);
        $this->app->scoped(
            ProjectLaborCostDatabasePort::class,
            static fn ($app): DatabaseProjectLaborCostAdapter => new DatabaseProjectLaborCostAdapter(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(ProjectLaborCostFormula::class),
            ),
        );
        $this->app->scoped(
            EffectiveLaborRateSource::class,
            static fn ($app): ProjectLaborCostDatabasePort => $app->make(ProjectLaborCostDatabasePort::class),
        );
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('time-tracking.active', EnsureTimeTrackingActive::class);
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }
}
