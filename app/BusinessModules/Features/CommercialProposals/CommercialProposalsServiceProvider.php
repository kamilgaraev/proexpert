<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals;

use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalExportService;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use Illuminate\Support\ServiceProvider;

final class CommercialProposalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommercialProposalService::class);
        $this->app->singleton(CommercialProposalExportService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }
    }
}
