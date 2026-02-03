<?php

namespace App\BusinessModules\Features\NormativeReferences;

use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Features\NormativeReferences\Console\Commands\ImportKsrCommand;
use App\BusinessModules\Features\NormativeReferences\Services\NormativeResourceImportService;

class NormativeReferencesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NormativeResourceImportService::class);
    }

    public function boot(): void
    {
        // Загрузка миграций
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        // Регистрация консольных команд
        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportKsrCommand::class,
            ]);
        }
    }
}
