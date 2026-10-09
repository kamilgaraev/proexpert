<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ApprovePaymentRequestTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\CreateScheduleTaskTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateContractorSettlementsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateContractPaymentsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateMaterialMovementsReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateOperationalPdfReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateProfitabilityReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateProjectTimelinesReportTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateRagPdfReportTool;
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
use App\BusinessModules\Features\AIAssistant\Console\Commands\BackfillRagIndexCommand;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialAnswerService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseFactSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseConstructionErpFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseContractFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseFinanceFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulsePeopleFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseProcurementFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseProjectFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseScheduleFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseSiteRequestFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseWarehouseFactSource;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseWorkFactSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ChangeManagementRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CommercialProcessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ConstructionJournalRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ContractRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CrmRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateGenerationLearningRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateReferenceRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\HandoverAcceptanceRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\KnowledgeHubRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\MachineryRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PaymentRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PeopleRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PerformanceActRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProcurementRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProductionLaborRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProjectPulseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProjectRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\QualityAndExecutiveDocsRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SafetyRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ScheduleRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SiteRequestRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\TimeTrackingRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WarehouseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkCompletionRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantRagReportSourceRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComposer;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComposerInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportPdfWriterInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSourceRetrieverInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\DompdfAssistantReportPdfWriter;
use App\BusinessModules\Features\DesignManagement\Services\DesignPulseFactSource;
use App\Support\AI\LunaModelPolicy;
use App\Support\AI\TokenBudgetService;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AIAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PublicCoreAssistantRuntime::class);
        $this->app->scoped(PublicCoreRequestService::class);
        $this->app->booted(static function (\Illuminate\Foundation\Application $app): void {
            $bootstrap = dirname(__DIR__, 4).'/docker/public-core/runtime.php';
            if (! is_file($bootstrap)) {
                return;
            }
            try {
                require_once $bootstrap;
                if (class_exists(\Most\PublicCore\AppRuntimeBootstrap::class, false)) {
                    \Most\PublicCore\AppRuntimeBootstrap::register($app);
                }
            } catch (\Throwable) {
                // Keep the default unavailable binding on missing/invalid startup.
            }
        });
        $this->mergeConfigFrom(
            __DIR__.'/config/ai-assistant.php', 'ai-assistant'
        );

        $this->app->scoped(AIAssistantService::class);
        $this->app->scoped(AssistantRequestLifecycle::class);
        $this->app->scoped(\App\BusinessModules\Features\AIAssistant\Services\AssistantReadConcurrencyLimiter::class);
        $this->app->scoped(AssistantDataAccessPolicy::class);
        $this->app->scoped(AssistantMemoryService::class);
        $this->app->scoped(AssistantFinancialAnswerService::class);
        $this->app->scoped(AssistantFinancialClaimVerifier::class);
        $this->app->scoped(AssistantStructuredFactVerifier::class);
        $this->app->bind(\App\BusinessModules\Features\Budgeting\Contracts\ExactProjectFinanceSourceRead::class,
            \App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService::class);
        $this->app->scoped(\App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyLiveEvidenceAdapter::class);
        $this->app->singleton(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class);
        $this->app->singleton(AssistantDomainCatalog::class, fn () => new AssistantDomainCatalog(AssistantDomainCatalog::defaults()));
        $this->app->scoped(TokenBudgetService::class, fn ($app) => new TokenBudgetService(
            calibrationCache: $app['cache']->store(),
            calibrationModel: LunaModelPolicy::forProvider((string) config('ai-assistant.llm.provider', 'timeweb')),
        ));

        // Динамический выбор LLM провайдера на основе конфигурации
        $this->app->singleton(AssistantCapabilityCatalog::class);
        $this->app->singleton(AssistantPeriodResolver::class);
        $this->app->singleton(AssistantAgentPlanner::class);
        $this->app->scoped(AssistantAgentExecutor::class);
        $this->app->singleton(AssistantResponseVerifier::class);
        $this->app->singleton(RagEmbeddingProviderInterface::class, function ($app): RagEmbeddingProviderInterface {
            $provider = strtolower((string) config('ai-assistant.rag.embedding_provider', 'timeweb'));

            return match ($provider) {
                'openai' => $app->make(OpenAIRagEmbeddingProvider::class),
                'timeweb' => new OpenAIRagEmbeddingProvider(
                    apiKey: config('ai-assistant.rag.embedding_api_key') ?: config('ai-assistant.llm.timeweb.api_key'),
                    model: config('ai-assistant.rag.embedding_model', 'text-embedding-3-small'),
                    dimensions: (int) config('ai-assistant.rag.embedding_dimensions', 256),
                    baseUri: config('ai-assistant.rag.embedding_base_uri') ?: config('ai-assistant.llm.timeweb.base_uri'),
                    providerName: 'timeweb'
                ),
                default => throw new InvalidArgumentException('ai_rag_embedding_provider_invalid'),
            };
        });
        $this->app->singleton(RagEmbeddingProviderRegistry::class, fn ($app) => new RagEmbeddingProviderRegistry(
            $app->make(RagEmbeddingProviderInterface::class),
        ));
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
                $app->make(PeopleRagSource::class),
                $app->make(TimeTrackingRagSource::class),
                $app->make(CrmRagSource::class),
                $app->make(CommercialProcessRagSource::class),
                $app->make(KnowledgeHubRagSource::class),
                $app->make(FileRagSource::class),
                $app->make(\App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\DesignManagementRagSource::class),
                ...array_map(static fn (string $class) => $app->make($class), \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('sourceClasses')),
            ]);
        });
        $this->app->singleton(RagIndexer::class);
        $this->app->scoped(RagRetriever::class);
        $this->app->singleton(RagPromptContextBuilder::class);
        $this->app->scoped(AssistantReportSourceRetrieverInterface::class, AssistantRagReportSourceRetriever::class);
        $this->app->scoped(AssistantReportComposerInterface::class, AssistantReportComposer::class);
        $this->app->singleton(AssistantReportPdfWriterInterface::class, DompdfAssistantReportPdfWriter::class);

        $this->app->singleton(LLMProviderInterface::class, function ($app) {
            $provider = strtolower((string) config('ai-assistant.llm.provider', 'timeweb'));

            return match ($provider) {
                'openai' => $app->make(OpenAIProvider::class),
                'timeweb' => $app->make(TimewebProvider::class),
                default => throw new InvalidArgumentException('ai_llm_provider_invalid'),
            };
        });

        // Регистрация реестра инструментов
        $this->app->scoped(AIToolRegistry::class, function ($app) {
            $registry = new AIToolRegistry;

            // Регистрируем инструменты
            $registry->registerFactory('generate_profitability_report', fn () => $app->make(GenerateProfitabilityReportTool::class));
            $registry->registerFactory('generate_work_completion_report', fn () => $app->make(GenerateWorkCompletionReportTool::class));
            $registry->registerFactory('generate_material_movements_report', fn () => $app->make(GenerateMaterialMovementsReportTool::class));
            $registry->registerFactory('generate_contractor_settlements_report', fn () => $app->make(GenerateContractorSettlementsReportTool::class));
            $registry->registerFactory('generate_warehouse_stock_report', fn () => $app->make(GenerateWarehouseStockReportTool::class));
            $registry->registerFactory('generate_time_tracking_report', fn () => $app->make(GenerateTimeTrackingReportTool::class));
            $registry->registerFactory('generate_contract_payments_report', fn () => $app->make(GenerateContractPaymentsReportTool::class));
            $registry->registerFactory('generate_project_timelines_report', fn () => $app->make(GenerateProjectTimelinesReportTool::class));
            $registry->registerFactory('generate_operational_pdf_report', fn () => $app->make(GenerateOperationalPdfReportTool::class));
            $registry->registerFactory('generate_rag_pdf_report', fn () => $app->make(GenerateRagPdfReportTool::class));
            $registry->registerFactory('get_project_snapshot', fn () => $app->make(GetProjectSnapshotTool::class));
            $registry->registerFactory('get_procurement_snapshot', fn () => $app->make(GetProcurementSnapshotTool::class));
            $registry->registerFactory('get_contract_snapshot', fn () => $app->make(GetContractSnapshotTool::class));
            $registry->registerFactory('get_schedule_snapshot', fn () => $app->make(GetScheduleSnapshotTool::class));

            // Phase 2: CRUD and Business Actions
            $registry->registerFactory('search_projects', fn () => $app->make(SearchProjectsTool::class));
            $registry->registerFactory('search_warehouse', fn () => $app->make(SearchWarehouseTool::class));
            $registry->registerFactory('search_materials', fn () => $app->make(SearchMaterialsTool::class));
            $registry->registerFactory('search_users', fn () => $app->make(SearchUsersTool::class));
            $registry->registerFactory('search_contractors', fn () => $app->make(SearchContractorsTool::class));
            $registry->registerFactory('approve_payment_request', fn () => $app->make(ApprovePaymentRequestTool::class));
            $registry->registerFactory('create_schedule_task', fn () => $app->make(CreateScheduleTaskTool::class));
            $registry->registerFactory('update_schedule_task_status', fn () => $app->make(UpdateScheduleTaskStatusTool::class));
            $registry->registerFactory('send_project_notification', fn () => $app->make(SendProjectNotificationTool::class));

            foreach ([
                'assistant_domain_discover_capabilities' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool::class,
                'search_assistant_documents' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool::class,
                'get_material_stock' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\GetMaterialStockTool::class,
                'get_bim_model_elements' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\GetBimModelElementsTool::class,
                'get_published_report_financial_evidence' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\GetPublishedReportFinancialEvidenceTool::class,
                'get_live_project_financial_evidence' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\GetLiveProjectFinancialEvidenceTool::class,
                'assistant_domain_search' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDomainTool::class,
                'assistant_domain_read' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\ReadAssistantDomainTool::class,
                'assistant_domain_navigation' => \App\BusinessModules\Features\AIAssistant\Actions\Domains\NavigationAssistantDomainTool::class,
                'resolve_estimate' => \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\ResolveEstimateTool::class,
                'get_estimate_answer' => \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimateAnswerTool::class,
                'get_estimate_positions' => \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimatePositionsTool::class,
                'search_estimate_positions' => \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\SearchEstimatePositionsTool::class,
                'get_estimate_financial_snapshot' => \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimateFinancialSnapshotTool::class,
                'create_measurement_unit' => \App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools\CreateMeasurementUnitTool::class,
                'update_measurement_unit' => \App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools\UpdateMeasurementUnitTool::class,
                'delete_measurement_unit' => \App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools\DeleteMeasurementUnitTool::class,
                'mass_create_measurement_units' => \App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools\MassCreateMeasurementUnitsTool::class,
            ] as $toolName => $toolClass) {
                $registry->registerFactory($toolName, fn () => $app->make($toolClass));
            }

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
                $app->make(ProjectPulseWorkFactSource::class),
                $app->make(DesignPulseFactSource::class),
                $app->make(ProjectPulsePeopleFactSource::class),
            ]);
        });
    }

    public function boot(): void
    {
        $indexingState = $this->app->make(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class);
        $this->app['events']->listen(\Illuminate\Database\Events\MigrationsStarted::class, static fn () => $indexingState->beginMigration());
        $this->app['events']->listen(\Illuminate\Database\Events\MigrationsEnded::class, static fn () => $indexingState->endMigration());

        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $this->loadRoutesFrom(__DIR__.'/routes.php');

        foreach (\App\BusinessModules\Features\AIAssistant\Observers\AssistantRagEntityObserver::models() as $modelClass) {
            $modelClass::observe(\App\BusinessModules\Features\AIAssistant\Observers\AssistantRagEntityObserver::class);
        }
        foreach ([\App\Models\Estimate::class, \App\Models\EstimateSection::class, \App\Models\EstimateItem::class, \App\Models\EstimateItemResource::class] as $modelClass) {
            $modelClass::observe(\App\BusinessModules\Features\AIAssistant\Observers\EstimateRagIndexObserver::class);
        }
        \App\Models\File::observe(\App\Observers\AssistantEntityFileObserver::class);
        \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::observe(\App\Observers\AssistantDocumentIndexObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillRagIndexCommand::class,
                \App\BusinessModules\Features\AIAssistant\Console\Commands\RecoverRagIndexRunsCommand::class,
                \App\BusinessModules\Features\AIAssistant\Console\Commands\PruneRagProjectionsCommand::class,
                \App\BusinessModules\Features\AIAssistant\Console\Commands\PurgeAssistantRetentionCommand::class,
                \App\BusinessModules\Features\AIAssistant\Console\Commands\ExpireAssistantRequestsCommand::class,
                \App\BusinessModules\Features\AIAssistant\Console\Commands\ScanAssistantDocumentsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/config/ai-assistant.php' => config_path('ai-assistant.php'),
            ], 'ai-assistant-config');
        }
    }
}
