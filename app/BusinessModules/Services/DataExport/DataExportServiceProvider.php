<?php

namespace App\BusinessModules\Services\DataExport;

use Illuminate\Support\ServiceProvider;

class DataExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DataExportModule::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        
        if (is_dir(__DIR__ . '/migrations')) {
            $this->loadMigrationsFrom(__DIR__ . '/migrations');
        }
    }
}
