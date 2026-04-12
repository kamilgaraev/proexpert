<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades;

use Illuminate\Support\ServiceProvider;

class BrigadesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BrigadesModule::class);
    }

    public function boot(): void
    {
    }
}
