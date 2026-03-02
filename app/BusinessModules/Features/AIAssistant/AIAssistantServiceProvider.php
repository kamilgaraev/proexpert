<?php

namespace App\BusinessModules\Features\AIAssistant;

use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\YandexGPTProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\DeepSeekProvider;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateProfitabilityReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateWorkCompletionReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateMaterialMovementsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateContractorSettlementsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateWarehouseStockReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateTimeTrackingReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateContractPaymentsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateProjectTimelinesReportTool;

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

        // Регистрация реестра инструментов
        $this->app->singleton(AIToolRegistry::class, function ($app) {
            $registry = new AIToolRegistry();
            
            // Регистрируем инструменты
            $registry->registerTool($app->make(GenerateProfitabilityReportTool::class));
            $registry->registerTool($app->make(GenerateWorkCompletionReportTool::class));
            $registry->registerTool($app->make(GenerateMaterialMovementsReportTool::class));
            $registry->registerTool($app->make(GenerateContractorSettlementsReportTool::class));
            $registry->registerTool($app->make(GenerateWarehouseStockReportTool::class));
            $registry->registerTool($app->make(GenerateTimeTrackingReportTool::class));
            $registry->registerTool($app->make(GenerateContractPaymentsReportTool::class));
            $registry->registerTool($app->make(GenerateProjectTimelinesReportTool::class));
            
            return $registry;
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

