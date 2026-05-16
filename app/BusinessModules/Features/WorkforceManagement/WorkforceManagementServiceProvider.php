<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement;

use Illuminate\Support\ServiceProvider;

final class WorkforceManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkforceManagementModule::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
