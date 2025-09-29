<?php

namespace App\BusinessModules\Addons\Integrations;

use Illuminate\Support\ServiceProvider;

class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntegrationsModule::class);
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
