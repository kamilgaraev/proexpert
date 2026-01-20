<?php

namespace App\BusinessModules\Addons\AIEstimates;

use App\BusinessModules\Addons\AIEstimates\Events\EstimateGenerationCompleted;
use App\BusinessModules\Addons\AIEstimates\Events\EstimateGenerationFailed;
use App\BusinessModules\Addons\AIEstimates\Listeners\NotifyUserAboutGeneration;
use App\BusinessModules\Addons\AIEstimates\Listeners\TrackUsageStatistics;
use App\BusinessModules\Addons\AIEstimates\Services\AIEstimateGenerationService;
use App\BusinessModules\Addons\AIEstimates\Services\Cache\CacheKeyGenerator;
use App\BusinessModules\Addons\AIEstimates\Services\Cache\CacheService;
use App\BusinessModules\Addons\AIEstimates\Services\CatalogMatchingService;
use App\BusinessModules\Addons\AIEstimates\Services\EstimateBuilderService;
use App\BusinessModules\Addons\AIEstimates\Services\Export\AIEstimateExportService;
use App\BusinessModules\Addons\AIEstimates\Services\FeedbackCollectorService;
use App\BusinessModules\Addons\AIEstimates\Services\FileProcessing\FileParserService;
use App\BusinessModules\Addons\AIEstimates\Services\FileProcessing\YandexVisionClient;
use App\BusinessModules\Addons\AIEstimates\Services\ProjectHistoryAnalysisService;
use App\BusinessModules\Addons\AIEstimates\Services\UsageLimitService;
use App\BusinessModules\Addons\AIEstimates\Services\YandexGPT\PromptBuilder;
use App\BusinessModules\Addons\AIEstimates\Services\YandexGPT\YandexGPTClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Log;

class AIEstimatesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        try {
            // Регистрация основного модуля
            $this->app->singleton(AIEstimatesModule::class);

            // YandexGPT и YandexVision клиенты
            $this->app->singleton(YandexGPTClient::class);
            $this->app->singleton(YandexVisionClient::class);
            $this->app->singleton(PromptBuilder::class);

            // Основные сервисы
            $this->app->singleton(AIEstimateGenerationService::class);
            $this->app->singleton(CatalogMatchingService::class);
            $this->app->singleton(ProjectHistoryAnalysisService::class);
            $this->app->singleton(EstimateBuilderService::class);
            $this->app->singleton(UsageLimitService::class);
            $this->app->singleton(FeedbackCollectorService::class);

            // Кеширование
            $this->app->singleton(CacheService::class);
            $this->app->singleton(CacheKeyGenerator::class);

            // Обработка файлов
            $this->app->singleton(FileParserService::class);

            // Экспорт
            $this->app->singleton(AIEstimateExportService::class);

            // Загрузка конфигурации модуля
            if (file_exists(config_path('ai-estimates.php'))) {
                $this->mergeConfigFrom(
                    config_path('ai-estimates.php'),
                    'ai-estimates'
                );
            }
        } catch (\Throwable $e) {
            Log::error('AIEstimatesServiceProvider register failed: ' . $e->getMessage());
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Загрузка маршрутов
        $this->loadRoutes();

        // Загрузка миграций
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        // Регистрация Events и Listeners
        $this->registerEvents();

        // Публикация конфигов (опционально)
        if (file_exists(__DIR__ . '/config/ai-estimates.php')) {
            $this->publishes([
                __DIR__ . '/config/ai-estimates.php' => config_path('ai-estimates.php'),
            ], 'ai-estimates-config');
        }
    }

    /**
     * Загрузка маршрутов
     */
    protected function loadRoutes(): void
    {
        $routesPath = __DIR__ . '/routes.php';
        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }

    /**
     * Регистрация событий и слушателей
     */
    protected function registerEvents(): void
    {
        Event::listen(
            EstimateGenerationCompleted::class,
            [NotifyUserAboutGeneration::class, 'handleCompleted']
        );

        Event::listen(
            EstimateGenerationFailed::class,
            [NotifyUserAboutGeneration::class, 'handleFailed']
        );

        Event::listen(
            EstimateGenerationCompleted::class,
            TrackUsageStatistics::class
        );
    }
}
