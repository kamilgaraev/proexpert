<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub;

use App\Console\Commands\SeedKnowledgeHubInitialContentCommand;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessContextFactory;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessFilter;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeArticleTreeService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeContextualHelpService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeFeedbackService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeFullTextSearchService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubQueryService;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeSearchAnalyticsService;
use Illuminate\Support\ServiceProvider;

class KnowledgeHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KnowledgeAccessContextFactory::class);
        $this->app->singleton(KnowledgeAccessFilter::class);
        $this->app->singleton(KnowledgeFullTextSearchService::class);
        $this->app->singleton(KnowledgeArticleTreeService::class);
        $this->app->singleton(KnowledgeContextualHelpService::class);
        $this->app->singleton(KnowledgeFeedbackService::class);
        $this->app->singleton(KnowledgeSearchAnalyticsService::class);
        $this->app->singleton(KnowledgeHubQueryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedKnowledgeHubInitialContentCommand::class,
            ]);
        }
    }
}
