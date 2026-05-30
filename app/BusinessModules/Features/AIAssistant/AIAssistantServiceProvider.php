<?php

namespace App\BusinessModules\Features\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Console\Commands\BackfillRagIndexCommand;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ApprovePaymentRequestTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\CreateScheduleTaskTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateContractorSettlementsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateContractPaymentsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateMaterialMovementsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateOperationalPdfReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateProfitabilityReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateProjectTimelinesReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateTimeTrackingReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateWarehouseStockReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateWorkCompletionReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetContractSnapshotTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetProcurementSnapshotTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetProjectSnapshotTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetScheduleSnapshotTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SearchContractorsTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SearchMaterialsTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SearchProjectsTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SearchUsersTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SearchWarehouseTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SendProjectNotificationTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\UpdateScheduleTaskStatusTool;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\LLM\DeepSeekProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\YandexGPTProvider;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseFactSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseContractFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseConstructionErpFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseFinanceFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulsePeopleFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseProcurementFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseProjectFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseReportFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseScheduleFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseSiteRequestFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseWarehouseFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseWorkFactSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\YandexRagEmbeddingProvider;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ChangeManagementRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ConstructionJournalRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ContractRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateGenerationLearningRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateReferenceRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\HandoverAcceptanceRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\MachineryRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PaymentRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PerformanceActRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProcurementRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProductionLaborRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProjectRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProjectPulseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\QualityAndExecutiveDocsRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SafetyRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ScheduleRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SiteRequestRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WarehouseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkCompletionRagSource;
use Illuminate\Support\ServiceProvider;

class AIAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/ai-assistant.php', 'ai-assistant'
        );

        // Динамический выбор LLM провайдера на основе конфигурации
        $this->app->singleton(AssistantCapabilityCatalog::class);
        $this->app->singleton(AssistantPeriodResolver::class);
        $this->app->singleton(AssistantAgentPlanner::class);
        $this->app->singleton(AssistantAgentExecutor::class);
        $this->app->singleton(AssistantResponseVerifier::class);
        $this->app->singleton(RagEmbeddingProviderInterface::class, function ($app): RagEmbeddingProviderInterface {
            $provider = config('ai-assistant.rag.embedding_provider', 'yandex');

            return match ($provider) {
                'yandex' => $app->make(YandexRagEmbeddingProvider::class),
                'openai' => $app->make(OpenAIRagEmbeddingProvider::class),
                default => $app->make(YandexRagEmbeddingProvider::class),
            };
        });
        $this->app->singleton(RagSourceRegistry::class, function ($app): RagSourceRegistry {
            return new RagSourceRegistry([
                $app->make(ProjectRagSource::class),
                $app->make(ScheduleRagSource::class),
                $app->make(ContractRagSource::class),
                $app->make(EstimateRagSource::class),
                $app->make(EstimateGenerationLearningRagSource::class),
                $app->make(EstimateReferenceRagSource::class),
                $app->make(ProcurementRagSource::class),
                $app->make(WarehouseRagSource::class),
                $app->make(SiteRequestRagSource::class),
                $app->make(WorkCompletionRagSource::class),
                $app->make(ConstructionJournalRagSource::class),
                $app->make(PerformanceActRagSource::class),
                $app->make(PaymentRagSource::class),
                $app->make(QualityAndExecutiveDocsRagSource::class),
                $app->make(ProjectPulseRagSource::class),
                $app->make(SafetyRagSource::class),
                $app->make(MachineryRagSource::class),
                $app->make(ProductionLaborRagSource::class),
                $app->make(ChangeManagementRagSource::class),
                $app->make(HandoverAcceptanceRagSource::class),
            ]);
        });
        $this->app->singleton(RagIndexer::class);
        $this->app->singleton(RagRetriever::class);
        $this->app->singleton(RagPromptContextBuilder::class);

        $this->app->singleton(LLMProviderInterface::class, function ($app) {
            $provider = config('ai-assistant.llm.provider', 'yandex');

            return match ($provider) {
                'yandex' => $app->make(YandexGPTProvider::class),
                'openai' => $app->make(OpenAIProvider::class),
                'deepseek' => $app->make(DeepSeekProvider::class),
                default => $app->make(YandexGPTProvider::class),
            };
        });

        // Регистрация реестра инструментов
        $this->app->singleton(AIToolRegistry::class, function ($app) {
            $registry = new AIToolRegistry;

            // Регистрируем инструменты
            $registry->registerTool($app->make(GenerateProfitabilityReportTool::class));
            $registry->registerTool($app->make(GenerateWorkCompletionReportTool::class));
            $registry->registerTool($app->make(GenerateMaterialMovementsReportTool::class));
            $registry->registerTool($app->make(GenerateContractorSettlementsReportTool::class));
            $registry->registerTool($app->make(GenerateWarehouseStockReportTool::class));
            $registry->registerTool($app->make(GenerateTimeTrackingReportTool::class));
            $registry->registerTool($app->make(GenerateContractPaymentsReportTool::class));
            $registry->registerTool($app->make(GenerateProjectTimelinesReportTool::class));
            $registry->registerTool($app->make(GenerateOperationalPdfReportTool::class));
            $registry->registerTool($app->make(GetProjectSnapshotTool::class));
            $registry->registerTool($app->make(GetProcurementSnapshotTool::class));
            $registry->registerTool($app->make(GetContractSnapshotTool::class));
            $registry->registerTool($app->make(GetScheduleSnapshotTool::class));

            // Phase 2: CRUD and Business Actions
            $registry->registerTool($app->make(SearchProjectsTool::class));
            $registry->registerTool($app->make(SearchWarehouseTool::class));
            $registry->registerTool($app->make(SearchMaterialsTool::class));
            $registry->registerTool($app->make(SearchUsersTool::class));
            $registry->registerTool($app->make(SearchContractorsTool::class));
            $registry->registerTool($app->make(ApprovePaymentRequestTool::class));
            $registry->registerTool($app->make(CreateScheduleTaskTool::class));
            $registry->registerTool($app->make(UpdateScheduleTaskStatusTool::class));
            $registry->registerTool($app->make(SendProjectNotificationTool::class));

            return $registry;
        });

        $this->app->singleton(ProjectPulseFactSourceRegistry::class, function ($app): ProjectPulseFactSourceRegistry {
            return new ProjectPulseFactSourceRegistry([
                $app->make(ProjectPulseProjectFactSource::class),
                $app->make(ProjectPulseSiteRequestFactSource::class),
                $app->make(ProjectPulseProcurementFactSource::class),
                $app->make(ProjectPulseWarehouseFactSource::class),
                $app->make(ProjectPulseFinanceFactSource::class),
                $app->make(ProjectPulseContractFactSource::class),
                $app->make(ProjectPulseScheduleFactSource::class),
                $app->make(ProjectPulseConstructionErpFactSource::class),
                $app->make(ProjectPulseReportFactSource::class),
                $app->make(ProjectPulseWorkFactSource::class),
                $app->make(ProjectPulsePeopleFactSource::class),
            ]);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillRagIndexCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/config/ai-assistant.php' => config_path('ai-assistant.php'),
            ], 'ai-assistant-config');
        }
    }
}
