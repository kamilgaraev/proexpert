<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations;

use Illuminate\Support\ServiceProvider;

final class MachineryOperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MachineryOperationsModule::class);
    }
}
