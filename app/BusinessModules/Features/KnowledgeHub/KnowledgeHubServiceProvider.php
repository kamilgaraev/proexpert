<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub;

use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubQueryService;
use Illuminate\Support\ServiceProvider;

class KnowledgeHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KnowledgeHubQueryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
