<?php

namespace App\BusinessModules\Features\AIAssistant;

use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\YandexGPTProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\DeepSeekProvider;

class AIAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/ai-assistant.php', 'ai-assistant'
        );

        // Динамический выбор LLM провайдера на основе конфигурации
        $this->app->singleton(LLMProviderInterface::class, function ($app) {
            $provider = config('ai-assistant.llm.provider', 'yandex');
            
            return match($provider) {
                'yandex' => $app->make(YandexGPTProvider::class),
                'openai' => $app->make(OpenAIProvider::class),
                'deepseek' => $app->make(DeepSeekProvider::class),
                default => $app->make(YandexGPTProvider::class),
            };
        });
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

