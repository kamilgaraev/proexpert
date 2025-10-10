<?php

namespace App\BusinessModules\Features\AIAssistant;

use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;

class AIAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/ai-assistant.php', 'ai-assistant'
        );

        $this->app->singleton(LLMProviderInterface::class, OpenAIProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/ai-assistant.php' => config_path('ai-assistant.php'),
            ], 'ai-assistant-config');
        }
    }
}

