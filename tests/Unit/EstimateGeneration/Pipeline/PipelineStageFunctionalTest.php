<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePlanResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\AssembleResourcesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\BuildDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ExtractQuantitiesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\MatchNormativesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ResolvePricesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\UnderstandDocumentsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\UnderstandObjectStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;

final class PipelineStageFunctionalTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('config', new Repository([
            'app' => ['fallback_locale' => 'ru'],
            'estimate-generation' => [
                'normative_matching' => [
                    'reranker' => ['models' => ['openai/test-model']],
                ],
            ],
        ]));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('translator', new class
        {
            public function get(string $key, array $replace = [], ?string $locale = null): string
            {
                return $key;
            }
        });
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    #[Test]
    public function confirmed_document_quantity_returns_to_the_normative_gate(): void
    {
        $plan = $this->unknownDocumentPlan();
        $feedback = new \App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateFeedbackService(
            $this->createMock(EstimateValidationService::class),
            $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService::class),
            static fn (string $key): string => $key,
        );
        $item = $plan['local_estimates'][0]['sections'][0]['work_items'][0];
        $confirmed = $feedback->applyQuantityConfirmationToDraft($plan, $item['key'], [
            'quantity' => 12, 'unit' => 'м2', 'quantity_basis' => 'Проверено по ведомости: 12 м2',
        ]);
        $workflow = new NormativeMatchingWorkflow(
            new NormativeRetrievalService(new class implements NormativeCandidateSource
            {
                public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
                {
                    throw new \LogicException('A missing dataset must block before retrieval.');
                }
            }, new NormativeHardGate, 16, null),
            new class implements NormativeCandidateRerankerInterface
            {
                public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
                {
                    throw new \LogicException('A missing dataset must block before reranking.');
                }
            },
        );
        $results = new StageResultFactory(new InMemoryPipelineArtifactStore, PipelineDefinitionGraph::standard());
        $stage = new MatchNormativesStage($this->createMock(ResourceAssemblyService::class), $workflow, new NormativeWorkIntentFactory, $results);
        $before = $stage->execute($this->documentStageContext(ProcessingStage::MatchNormatives, [ProcessingStage::PlanWorkItems->value => $plan], $results));
        self::assertSame('quantity_review', $before->transientData['local_estimates'][0]['sections'][0]['work_items'][0]['item_type']);
        self::assertArrayNotHasKey('normative_retrieval', $before->transientData['local_estimates'][0]['sections'][0]['work_items'][0]);
        $plan['local_estimates'] = $confirmed['local_estimates'];
        $after = $stage->execute($this->documentStageContext(ProcessingStage::MatchNormatives, [ProcessingStage::PlanWorkItems->value => $plan], $results));
        $matched = $after->transientData['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('priced_work', $matched['item_type']);
        self::assertSame(12.0, $matched['quantity']);
        self::assertSame('normative_dataset_not_pinned', $matched['pricing_blocker']);
        self::assertSame(['normative_dataset_not_pinned'], $matched['normative_retrieval']['blocking_issues']);
    }

    #[Test]
    public function real_price_stage_keeps_unknown_document_costs_null(): void
    {
        $plan = $this->unknownDocumentPlan();
        $observed = $plan['local_estimates'][0]['sections'][0]['work_items'][0];
        $observed['key'] .= '-observed';
        $observed['quantity'] = '12';
        $plan['local_estimates'][0]['sections'][0]['work_items'][] = $observed;
        $data = ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => $plan['local_estimates']];
        $results = new StageResultFactory(new InMemoryPipelineArtifactStore, PipelineDefinitionGraph::standard());
        $result = (new ResolvePricesStage(new EstimatePricingService, $results))->execute(
            $this->documentStageContext(ProcessingStage::ResolvePrices, [ProcessingStage::AssembleResources->value => $data], $results),
        );
        [$unknown, $known] = $result->transientData['local_estimates'][0]['sections'][0]['work_items'];
        self::assertNull($unknown['quantity']);
        foreach (['work_cost', 'materials_cost', 'machinery_cost', 'labor_cost', 'other_cost', 'total_cost'] as $field) {
            self::assertNull($unknown[$field], $field);
        }
        self::assertSame('not_calculated', $unknown['pricing_status']);
        self::assertSame('quantity_review_required', $unknown['pricing_blocker']);
        self::assertSame(0, $known['total_cost']);
        $built = (new BuildDraftStage($results))->execute($this->documentStageContext(ProcessingStage::BuildDraft, [
            ProcessingStage::UnderstandDocuments->value => [
                'base_input_version' => 'sha256:'.str_repeat('a', 64), 'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null,
            ],
            ProcessingStage::UnderstandObject->value => ['analysis' => ['object' => ['description' => 'Кладка стен']]],
            ProcessingStage::ExtractQuantities->value => [
                'quantity_learning_hints' => [], 'quantity_coverage_warnings' => [], 'building_quantities' => [], 'stage6_generation_context' => [],
            ],
            ProcessingStage::PlanWorkItems->value => $plan,
            ProcessingStage::ResolvePrices->value => $result->transientData,
        ], $results));
        $candidates = $built->transientData['draft']['stage6_candidate_rows'];
        self::assertCount(2, $candidates);
        self::assertNull($candidates[0]['quantity']);
        self::assertNull($candidates[0]['total_cost']);
        self::assertSame('0', $candidates[1]['total_cost']);
        $plan['local_estimates'][0]['sections'][0]['work_items'][1] = [...$unknown, 'key' => $unknown['key'].'-overflow'];
        $budgeted = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->build([
            ...$plan, 'stage6_limits' => ['max_rows' => 1],
        ]);
        self::assertSame('draft_row_budget_exceeded', $budgeted['stage6_candidate_rows'][1]['pricing_blocker']);
        self::assertNull($budgeted['stage6_candidate_rows'][1]['quantity']);
        self::assertNull($budgeted['stage6_candidate_rows'][1]['total_cost']);
    }

    #[Test]
    public function an_unaccepted_document_candidate_can_receive_a_quantity_confirmation(): void
    {
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->build($this->unknownDocumentPlan());
        $candidate = $draft['stage6_candidate_rows'][0];
        $feedback = new \App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateFeedbackService(
            $this->createMock(EstimateValidationService::class),
            $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService::class),
            static fn (string $key): string => $key,
            static function (array $errors): \Illuminate\Validation\ValidationException {
                throw new \LogicException(json_encode($errors, JSON_THROW_ON_ERROR));
            },
        );
        $confirmed = $feedback->applyQuantityConfirmationToDraft($draft, $candidate['key'], [
            'quantity' => 12, 'unit' => 'm2', 'quantity_basis' => 'Измерено пользователем.',
        ]);
        self::assertSame(12.0, $confirmed['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        self::assertFalse($confirmed['is_complete']);
    }

    #[Test]
    public function non_payable_rows_keep_zero_cost_when_reviewed_or_over_budget(): void
    {
        foreach (['operation', 'resource_note', 'review_note'] as $type) {
            $plan = $this->unknownDocumentPlan();
            $item = [...$plan['local_estimates'][0]['sections'][0]['work_items'][0], 'item_type' => $type, 'quantity' => null, 'total_cost' => 0];
            $plan['local_estimates'][0]['sections'][0]['work_items'] = [$item, [...$item, 'key' => $item['key'].'-overflow']];
            $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->build([
                ...$plan, 'stage6_limits' => ['max_rows' => 1],
            ]);
            self::assertCount(2, $draft['stage6_candidate_rows']);
            self::assertSame('0', $draft['stage6_candidate_rows'][0]['total_cost'], $type);
            self::assertSame('draft_row_budget_exceeded', $draft['stage6_candidate_rows'][1]['pricing_blocker']);
            self::assertSame('0', $draft['stage6_candidate_rows'][1]['total_cost'], $type);
        }
    }

    private function unknownDocumentPlan(): array
    {
        $section = ['key' => 'walls-section', 'construction_part' => 'walls'];
        $package = ['key' => 'walls', 'scope_type' => 'walls'];
        $items = (new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService))->build($package, $section, [
            'source_documents' => [[
                'id' => 82, 'filename' => 'volumes.pdf', 'status' => 'ready', 'quality' => ['level' => 'good'],
                'document_understanding' => ['role_for_estimation' => 'quantity_source'], 'text' => 'ФЕР08-02-001-01 Кладка стен м2',
            ]],
        ]);
        $references = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference'));
        self::assertCount(1, $references);

        return [
            'object_profile' => ['object_type' => 'house'], 'package_plan' => [], 'document_requirements' => [],
            'generation_mode' => 'strict', 'regional_context' => [], 'normative_context_pin' => [],
            'estimate_composition' => ['schema_version' => 1, 'snapshot_token' => str_repeat('c', 64), 'input_fingerprint' => str_repeat('d', 64), 'intents_count' => 1, 'derived_quantities' => []],
            'local_estimates' => [[...$package, 'sections' => [[...$section, 'work_items' => $references]]]],
        ];
    }

    private function documentStageContext(ProcessingStage $stage, array $payloads, StageResultFactory $results): PipelineContext
    {
        $base = 'sha256:'.str_repeat('a', 64);
        $graph = PipelineDefinitionGraph::standard();
        $outputs = [];
        foreach ($payloads as $key => $payload) {
            $dependencyStage = ProcessingStage::from($key);
            $versions = [];
            foreach ($graph->get($dependencyStage)->dependencies as $dependency) {
                $versions[$dependency->value] = $base;
            }
            $result = $results->make(new PipelineContext(1, 2, 3, 1, $base, 'generating',
                generationAttemptId: '00000000-0000-4000-8000-000000000002', baseInputVersion: $base,
                stage: $dependencyStage, dependencyVersions: $versions), $dependencyStage, $payload);
            $outputs[$key] = $result->output;
        }
        $versions = [];
        foreach ($graph->get($stage)->dependencies as $dependency) {
            $versions[$dependency->value] = $outputs[$dependency->value]->version;
        }

        return new PipelineContext(1, 2, 3, 1, $base, 'generating',
            priorOutputs: new PipelinePriorOutputs($outputs, $payloads),
            generationAttemptId: '00000000-0000-4000-8000-000000000002', baseInputVersion: $base,
            stage: $stage, dependencyVersions: $versions);
    }

    #[Test]
    public function it_excludes_a_work_item_and_records_a_scope_boundary_when_its_modern_pin_has_no_candidate(): void
    {
        $artifacts = new InMemoryPipelineArtifactStore;
        $graph = PipelineDefinitionGraph::standard();
        $results = new StageResultFactory($artifacts, $graph);
        $base = 'sha256:'.str_repeat('a', 64);
        $attempt = '00000000-0000-4000-8000-000000000002';
        $warning = [
            'quantity_key' => 'roof.covering',
            'reason' => 'normative_candidate_missing',
            'package_key' => 'roof',
        ];
        $plan = [
            'object_profile' => ['object_type' => 'house'],
            'package_plan' => [],
            'document_requirements' => [],
            'generation_mode' => 'strict',
            'regional_context' => [],
            'normative_context_pin' => [
                'dataset_version' => 'fsnb-2026.1',
                'applicability_date' => '2026-07-13',
                'catalog_candidates' => [],
                'candidate_ids_by_work_item' => ['roof-covering' => []],
            ],
            'estimate_composition' => [
                'schema_version' => 1,
                'snapshot_token' => str_repeat('c', 64),
                'input_fingerprint' => str_repeat('d', 64),
                'intents_count' => 1,
                'derived_quantities' => [],
            ],
            'local_estimates' => [[
                'key' => 'roof',
                'coverage_warnings' => [],
                'sections' => [[
                    'work_items' => [
                        [
                            'key' => 'roof-covering',
                            'name' => 'Устройство кровельного покрытия',
                            'unit' => 'm2',
                            'quantity' => '10',
                            'quantity_formula' => 'roof.covering',
                            'item_type' => 'priced_work',
                        ],
                        [
                            'key' => 'roof-reference',
                            'name' => 'Справочная позиция',
                            'item_type' => 'operation',
                        ],
                    ],
                ]],
            ]],
        ];
        $planned = $results->make(
            new PipelineContext(
                1,
                2,
                3,
                4,
                $base,
                'generating',
                generationAttemptId: $attempt,
                baseInputVersion: $base,
                stage: ProcessingStage::PlanWorkItems,
                dependencyVersions: [
                    ProcessingStage::UnderstandObject->value => $base,
                    ProcessingStage::ExtractQuantities->value => $base,
                ],
            ),
            ProcessingStage::PlanWorkItems,
            $plan,
        );
        self::assertNotNull($planned->output);
        $context = new PipelineContext(
            1,
            2,
            3,
            5,
            $base,
            'generating',
            priorOutputs: new PipelinePriorOutputs(
                [ProcessingStage::PlanWorkItems->value => $planned->output],
                [ProcessingStage::PlanWorkItems->value => $plan],
            ),
            generationAttemptId: $attempt,
            baseInputVersion: $base,
            stage: ProcessingStage::MatchNormatives,
            dependencyVersions: [ProcessingStage::PlanWorkItems->value => $planned->output->version],
        );
        $workflow = new NormativeMatchingWorkflow(
            new NormativeRetrievalService(
                new class implements NormativeCandidateSource
                {
                    public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
                    {
                        throw new \LogicException('The workflow must not search when the modern pin has no candidate.');
                    }
                },
                new NormativeHardGate,
                16,
                null,
            ),
            new class implements NormativeCandidateRerankerInterface
            {
                public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
                {
                    throw new \LogicException('The workflow must not rerank when the modern pin has no candidate.');
                }
            },
        );
        $result = (new MatchNormativesStage(
            $this->createMock(ResourceAssemblyService::class),
            $workflow,
            new NormativeWorkIntentFactory(
                new WorkIntentClassifier(new NormativeScopeRuleCatalog),
                new NormativeRerankerModelSet(['openai/test-model']),
            ),
            $results,
        ))->execute($context);
        $output = $result->transientData;

        self::assertNotNull($output);
        self::assertSame([$warning], $output['local_estimates'][0]['coverage_warnings']);
        $workItems = $output['local_estimates'][0]['sections'][0]['work_items'];
        self::assertSame(['roof-reference'], array_column($workItems, 'key'));
        self::assertArrayNotHasKey(1, $workItems);
        self::assertSame([], array_values(array_filter(
            $workItems,
            static fn (array $workItem): bool => ($workItem['pricing_blocker'] ?? null) === 'normative_not_found'
                || in_array('normative_not_found', $workItem['validation_flags'] ?? [], true)
                || ($workItem['item_type'] ?? null) === 'review_note',
        )));
    }

    #[Test]
    public function it_preserves_unmapped_supplementary_work_for_server_side_normative_selection(): void
    {
        $artifacts = new InMemoryPipelineArtifactStore;
        $results = new StageResultFactory($artifacts, PipelineDefinitionGraph::standard());
        $base = 'sha256:'.str_repeat('b', 64);
        $attempt = '00000000-0000-4000-8000-000000000003';
        $plan = [
            'object_profile' => ['object_type' => 'house'],
            'package_plan' => [],
            'document_requirements' => [],
            'generation_mode' => 'strict',
            'regional_context' => [],
            'normative_context_pin' => [
                'dataset_version' => 'fsnb-2026.1',
                'applicability_date' => '2026-07-13',
                'catalog_candidates' => [],
                'candidate_ids_by_work_item' => ['existing-work' => []],
            ],
            'estimate_composition' => [
                'schema_version' => 1,
                'snapshot_token' => str_repeat('c', 64),
                'input_fingerprint' => str_repeat('d', 64),
                'intents_count' => 1,
                'derived_quantities' => [],
            ],
            'local_estimates' => [[
                'key' => 'roof',
                'coverage_warnings' => [],
                'sections' => [[
                    'work_items' => [
                        [
                            'key' => 'roof-covering',
                            'name' => 'Устройство кровельного покрытия',
                            'unit' => 'm2',
                            'quantity' => '10',
                            'quantity_formula' => 'roof.covering',
                            'item_type' => 'priced_work',
                            'composition_intent' => ['kind' => 'supplementary'],
                        ],
                        ['key' => 'roof-reference', 'name' => 'Справочная позиция', 'item_type' => 'operation'],
                    ],
                ]],
            ]],
        ];
        $planned = $results->make(
            new PipelineContext(
                1,
                2,
                3,
                4,
                $base,
                'generating',
                generationAttemptId: $attempt,
                baseInputVersion: $base,
                stage: ProcessingStage::PlanWorkItems,
                dependencyVersions: [
                    ProcessingStage::UnderstandObject->value => $base,
                    ProcessingStage::ExtractQuantities->value => $base,
                ],
            ),
            ProcessingStage::PlanWorkItems,
            $plan,
        );
        self::assertNotNull($planned->output);
        $context = new PipelineContext(
            1,
            2,
            3,
            5,
            $base,
            'generating',
            priorOutputs: new PipelinePriorOutputs(
                [ProcessingStage::PlanWorkItems->value => $planned->output],
                [ProcessingStage::PlanWorkItems->value => $plan],
            ),
            generationAttemptId: $attempt,
            baseInputVersion: $base,
            stage: ProcessingStage::MatchNormatives,
            dependencyVersions: [ProcessingStage::PlanWorkItems->value => $planned->output->version],
        );
        $workflow = new NormativeMatchingWorkflow(
            new NormativeRetrievalService(
                new class implements NormativeCandidateSource
                {
                    public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
                    {
                        throw new \LogicException('Supplementary selection must not search outside the pinned catalog.');
                    }
                },
                new NormativeHardGate,
                16,
                null,
            ),
            new class implements NormativeCandidateRerankerInterface
            {
                public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
                {
                    throw new \LogicException('Supplementary selection must not rerank when the pinned catalog is empty.');
                }
            },
        );
        $result = (new MatchNormativesStage(
            $this->createMock(ResourceAssemblyService::class),
            $workflow,
            new NormativeWorkIntentFactory(
                new WorkIntentClassifier(new NormativeScopeRuleCatalog),
                new NormativeRerankerModelSet(['openai/test-model']),
            ),
            $results,
        ))->execute($context);
        $output = $result->transientData;

        self::assertNotNull($output);
        self::assertSame([], $output['local_estimates'][0]['coverage_warnings']);
        $workItems = $output['local_estimates'][0]['sections'][0]['work_items'];
        self::assertSame(['roof-covering', 'roof-reference'], array_column($workItems, 'key'));
        self::assertSame('normative_not_found', $workItems[0]['pricing_blocker']);
        self::assertNotContains('normative_candidate_missing', $workItems[0]['validation_flags']);
    }

    #[Test]
    #[DataProvider('pinnedPriceCases')]
    public function real_stage_boundaries_consume_exact_typed_dependencies(bool $knownPricedPart, bool $roomFloorCase = false): void
    {
        $regional = $knownPricedPart ? [
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'synthetic-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'synthetic-prices-2026.07',
            'business_date' => '2026-07-13',
        ] : [];
        $gateway = new class($regional, $knownPricedPart, $roomFloorCase) implements GenerationPipelineDataGateway
        {
            public function __construct(private array $regional, private bool $knownPricedPart, private bool $roomFloorCase) {}

            public function manifest(PipelineContext $context): array
            {
                return ['base_input_version' => (string) $context->baseInputVersion, 'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null];
            }

            public function source(PipelineContext $context): array
            {
                return ['input' => [
                    'description' => $this->knownPricedPart
                        ? 'Отделка комнаты: монтаж плинтусов. Состав остальных работ не определён.'
                        : 'Полное строительство одноэтажного жилого дома под ключ, включая отопление и канализацию',
                    'area' => 80,
                    'generation_mode' => 'ai_assisted',
                    'regional_context' => $this->regional,
                    ...($this->roomFloorCase ? ['evaluation_mode' => 'universal', 'profile_id' => 'construction',
                        'construction_type' => 'new_construction', 'price_policy' => 'normative', 'selected_sections' => ['finishing']] : []),
                ], 'documents' => [], 'user_id' => 7, 'document_total_area' => [
                    'amount' => '80.000000',
                    'evidence_id' => 701,
                    'confidence' => 0.95,
                    'floor_count' => 1,
                ]];
            }
        };
        $matcher = new ResourceAssemblyService(
            $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher::class),
            new NormativeMatchDecisionService,
            new NormativeCandidatePresenter,
        );
        $pinSource = new class implements NormativeContextPinSource
        {
            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                $catalog = [[
                    'candidate_id' => '101', 'normative_id' => 101, 'dataset_id' => 77,
                    'dataset_version' => 'synthetic-2026.1', 'dataset_status' => 'parsed', 'code' => '11-01-999-01',
                    'name' => 'Монтаж плинтусов', 'unit' => '100 m',
                    'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
                    'section' => ['code' => '11', 'name' => 'Полы'], 'work_composition' => ['Монтаж плинтусов'],
                    'resources' => ['materials' => [], 'labor' => [[
                        'code' => 'labor-1', 'name' => 'Рабочие', 'unit' => 'person_hour', 'quantity' => 100,
                        'price_id' => 9001, 'price_source' => 'regional_catalog', 'linked_resource_id' => 501,
                        'norm_resource_id' => 7001,
                    ]], 'machinery' => [], 'other' => []],
                ]];
                $links = [];
                foreach ($intents as $intent) {
                    $links[$intent['work_item_key']] = ['101'];
                }

                return new NormativeContextPinData(
                    $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
                    $requested->regionId, $requested->priceZoneId, $requested->periodId,
                    $requested->regionalPriceVersionId, $requested->priceVersion,
                    $catalog, hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR)), [], $links,
                );
            }
        };
        $source = new class implements NormativeCandidateSource
        {
            public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
            {
                return [];
            }
        };
        $reranker = new class implements NormativeCandidateRerankerInterface
        {
            public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
            {
                throw new \LogicException('Empty retrieval must not rerank.');
            }
        };
        $workflow = new NormativeMatchingWorkflow(new NormativeRetrievalService($source, new NormativeHardGate, 16, null), $reranker);
        $artifacts = new InMemoryPipelineArtifactStore;
        $graph = PipelineDefinitionGraph::standard();
        $results = new StageResultFactory($artifacts, $graph);
        $base = 'sha256:'.str_repeat('a', 64);
        $projectModels = new \Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;
        $room = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity(
            'room:1', 1, 2, 3, $base, 'room', 'room:1',
        );
        $lengthEvidence = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence(
            'evidence:room:length', 1, 2, 3, $base, 'artifact:plan', 'document', 1, null, 'room:length', 'native_numeric_parser',
            fingerprint: hash('sha256', 'room:length'),
        );
        $widthEvidence = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence(
            'evidence:room:width', 1, 2, 3, $base, 'artifact:plan', 'document', 1, null, 'room:width', 'native_numeric_parser',
            fingerprint: hash('sha256', 'room:width'),
        );
        $nativeFacts = [
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact(
                'fact:room:length', 1, 2, 3, $base, $room->id, 'length', '10', 'm', 1.0,
                'document', 'confirmed', [$lengthEvidence->id],
                nativeNumericProofs: [$lengthEvidence->id => ['entity_id' => $room->id, 'source_version' => $base,
                    'parameter' => 'length', 'measurement' => 'm:10', 'evidence_fingerprint' => $lengthEvidence->fingerprint]],
            ),
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact(
                'fact:room:width', 1, 2, 3, $base, $room->id, 'width', $roomFloorCase ? '7.6' : '8', 'm', 1.0,
                'document', 'confirmed', [$widthEvidence->id],
                nativeNumericProofs: [$widthEvidence->id => ['entity_id' => $room->id, 'source_version' => $base,
                    'parameter' => 'width', 'measurement' => $roomFloorCase ? 'm:7.6' : 'm:8', 'evidence_fingerprint' => $widthEvidence->fingerprint]],
            ),
        ];
        $projectModels->saveSourceModel([$room], $knownPricedPart ? [$nativeFacts[0]] : $nativeFacts,
            $knownPricedPart ? [$lengthEvidence] : [$lengthEvidence, $widthEvidence]);
        if ($roomFloorCase) {
            $secondRoom = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity(
                'room:2', 1, 2, 3, $base, 'room', 'room:2');
            $areaEvidence = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence(
                'evidence:room:2:area', 1, 2, 3, $base, 'artifact:plan', 'document', 1, null, 'room:2:area', 'native_numeric_parser',
                fingerprint: hash('sha256', 'room:2:area'));
            $area = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact(
                'fact:room:2:area', 1, 2, 3, $base, $secondRoom->id, 'area', '4', 'm2', 1.0, 'document', 'confirmed', [$areaEvidence->id],
                nativeNumericProofs: [$areaEvidence->id => ['entity_id' => $secondRoom->id, 'source_version' => $base,
                    'parameter' => 'area', 'measurement' => 'm2:4', 'evidence_fingerprint' => $areaEvidence->fingerprint]]);
            $projectModels->saveSourceModel([$secondRoom], [$area], [$areaEvidence]);
        }
        $selectedSystem = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact(
            'fact:selected-floor-system', 1, 2, 3, $base, $room->id, 'selected_floor_system',
            ['kind' => 'catalog_system', 'system_id' => 'synthetic-baseboard', 'catalog_version' => 'technology:v1', 'catalog_hash' => str_repeat('c', 64)],
            null, 1.0, 'user_assumption', 'confirmed', [],
        );
        $projectModels->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision(
            'decision:floor-system', 1, 2, 3, $base, 'fact', $selectedSystem->id, $selectedSystem->id,
            'user', 'actor:7', 'Выбран способ монтажа плинтуса в синтетическом тесте', 1,
        ), $selectedSystem);
        $snapshotToken = $projectModels->snapshotForPlanning(1, 2, 3, 100)['token'];
        self::assertTrue($projectModels->replaceTechnologyRecommendations(
            1, 2, 3, $base, $snapshotToken, 'technology:v1', str_repeat('c', 64), [], [],
        ));
        $technologyPackage = new \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackage(
            'package:floor',
            [[
                'id' => 'work:floor',
                'label' => 'Монтаж плинтусов',
                'quantity_formula_id' => 'formula:floor',
                'norm_intent_id' => 'intent:floor',
            ]],
            [],
            [],
            [['id' => 'intent:floor', 'candidate_refs' => ['baseboard_installation']]],
            [[
                'id' => 'formula:floor',
                'unit' => 'm',
                'operands' => [['fact_id' => 'fact:room:length']],
            ]],
            [],
            [],
            [],
            [],
            ['rule_id' => 'floor'],
        );
        $finding = new \App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding(
            'floor', '1.0.0', str_repeat('d', 64), 'finding:floor', 1,
            'technology_required', 'proven_missing', 'warning', 'Требуется устройство пола', 1.0,
            ['fact:room:length'], [$room->id], ['length'], ['status' => 'applicable'],
            ['allowed' => false], null, $technologyPackage,
        );
        self::assertTrue($projectModels->replaceCompleteness(
            1, 2, 3, $base, $snapshotToken, 'technology:v1', str_repeat('c', 64),
            'rules:v1', str_repeat('d', 64), [$finding], [],
        ));
        self::assertNotNull($projectModels->currentTechnologyRecommendations(1, 2, 3));
        self::assertNotNull($projectModels->currentCompleteness(1, 2, 3));
        $canonicalQuantities = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService(
            $projectModels,
            new \App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory,
        );
        $auditor = new class implements EstimateAuditModel
        {
            public int $calls = 0;

            public function audit(EstimateAuditInput $input, callable $onAttemptStarted): array
            {
                $this->calls++;
                $onAttemptStarted('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

                return ['accepted' => true, 'findings' => []];
            }
        };
        $quantityEvidence = new \App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
        $stages = [
            new UnderstandDocumentsStage($gateway, $results),
            new UnderstandObjectStage(new ConstructionSemanticParser, $gateway, $results),
            new ExtractQuantitiesStage(
                new EstimateGenerationQuantityLearningEvidenceService,
                $results,
                $canonicalQuantities,
            ),
            new PlanWorkItemsStage(
                new \App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler(new PackagePlannerService, new EstimateDecompositionService, new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService), new NormativeContextPinResolver($pinSource)),
                $results,
                new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer($quantityEvidence),
                new RunEstimateComposer(
                    new InMemoryAiRoleRunRepository,
                    new class implements EstimateComposerModel
                    {
                        public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
                        {
                            $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

                            return ['work_intents' => array_map(static fn (array $candidate): array => [
                                'kind' => 'existing',
                                'candidate_id' => $candidate['candidate_id'],
                                'work_key' => null,
                                'name' => null,
                                'derived_quantity_id' => null,
                                'source_fact_ids' => [],
                                'technology_package_candidate' => $candidate['technology_package_candidate'],
                                'assumptions' => [],
                                'exclusions' => [],
                                'missing_document_recommendations' => [],
                            ], $input->candidates)];
                        }
                    },
                    'test-model',
                ),
                new EstimateComposerInputFactory($projectModels, 10000),
                new EstimateCompositionProjector,
            ),
            new MatchNormativesStage($matcher, $workflow, new NormativeWorkIntentFactory, $results),
            new AssembleResourcesStage(new AssembleMatchedResources, $results),
            new ResolvePricesStage(new EstimatePricingService(new ResolveRegionalPrice(static fn (int $id): array => [
                'id' => $id, 'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
                'regional_price_version_id' => 11, 'base_price' => '3.50', 'source_type' => 'regional_catalog',
            ]), acceptedEvidence: new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier($quantityEvidence)), $results),
            new BuildDraftStage($results),
            new ValidateDraftStage(
                new EstimateValidationService,
                new DraftReadinessProjector,
                $results,
                new ApplyComposerCorrectionCycle(new RunEstimateAudit(
                    new InMemoryAiRoleRunRepository,
                    $auditor,
                    'test-model',
                ), new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection(
                    new InMemoryAiRoleRunRepository,
                    new class implements \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel
                    {
                        public function correct(
                            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput $input,
                            callable $onPhysicalAttemptReserved,
                        ): array {
                            $onPhysicalAttemptReserved('cccccccc-cccc-4ccc-8ccc-cccccccccccc');

                            return ['corrections' => []];
                        }
                    },
                    'test-model',
                )),
                new EstimateAuditInputFactory(
                    $projectModels,
                    $quantityEvidence,
                    10000,
                ),
            ),
        ];
        $attempt = '00000000-0000-4000-8000-000000000001';
        $state = new InMemoryPipelineStateStore($artifacts);
        $resolver = new PipelinePlanResolver($graph, $state, $state);
        $runner = new PipelineRunner(
            new PipelineRegistry($stages), $state,
            new FailureRecorder(new class implements FailureStore
            {
                public function record(FailureData $failure, DateTimeImmutable $seenAt): void {}

                public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode, DateTimeImmutable $resolvedAt): bool
                {
                    return true;
                }

                public function resolveActive(FailureContext $context, string $resolutionCode, DateTimeImmutable $resolvedAt): int
                {
                    return 0;
                }
            }),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-11T10:00:00+00:00'),
        );
        $seed = new PipelineContext(
            sessionId: 3,
            organizationId: 1,
            projectId: 2,
            stateVersion: 4,
            inputVersion: $base,
            sessionStatus: 'generating',
            generationAttemptId: $attempt,
            baseInputVersion: $base,
        );
        for ($invocation = 0; $invocation < ($roomFloorCase ? 4 : 9); $invocation++) {
            $context = $resolver->next($seed);
            self::assertNotNull($context);
            if ($context->stage === ProcessingStage::ExtractQuantities) {
                self::assertNotNull($projectModels->currentTechnologyRecommendations(1, 2, 3), 'technology stale before stage 6');
                self::assertNotNull($projectModels->currentCompleteness(1, 2, 3), 'completeness stale before stage 6');
            }
            if ($context->stage === ProcessingStage::ValidateDraft && $knownPricedPart) {
                $build = $state->priorOutputs($seed)->payload(ProcessingStage::BuildDraft)['draft'];
                $acceptedCount = 0;
                foreach ($build['local_estimates'] as $estimate) {
                    foreach ($estimate['sections'] as $section) {
                        $acceptedCount += count($section['work_items']);
                    }
                }
                self::assertSame(1, $acceptedCount);
                $validated = (new DraftReadinessProjector)->project((new EstimateValidationService)->validate($build));
                $auditInput = (new EstimateAuditInputFactory($projectModels, $quantityEvidence, 10000))->capture(1, 2, 3, $validated);
                $wire = \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['audit_input' => $auditInput->canonicalPayload()]);
                self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextPayloadBudget)->fits($wire),
                    'Bytes: '.strlen($wire).'; tokens: '.(new \App\Support\AI\TokenCounter)->text($wire));
            }
            $result = $runner->runNext($context);
            if ($context->stage === ProcessingStage::ExtractQuantities) {
                self::assertNotEmpty($result?->transientData['stage6_generation_context']['work_packages'] ?? []);
            }
            self::assertSame($context->stage, $result?->stage);
        }
        if (! $roomFloorCase) {
            self::assertNull($resolver->next($seed));
        }
        $planned = $state->priorOutputs($seed)->payload(ProcessingStage::PlanWorkItems);
        $plannedItems = [];
        foreach ($planned['local_estimates'] as $localEstimate) {
            foreach ($localEstimate['sections'] as $section) {
                foreach ($section['work_items'] as $workItem) {
                    $quantityKey = $workItem['metadata']['quantity_key'] ?? null;
                    if (is_string($quantityKey) && $quantityKey !== '') {
                        $plannedItems[$quantityKey] = $workItem;
                    }
                }
            }
        }
        $floorPackageQuantity = 'quantity:technology_work_package:package:floor:formula:floor';
        self::assertArrayHasKey($floorPackageQuantity, $plannedItems);
        self::assertSame('Монтаж плинтусов', $plannedItems[$floorPackageQuantity]['name']);
        self::assertSame('m', $plannedItems[$floorPackageQuantity]['unit']);
        self::assertSame('package:floor', $plannedItems[$floorPackageQuantity]['metadata']['technology_package_id']);
        self::assertSame('intent:floor', $plannedItems[$floorPackageQuantity]['metadata']['normative_intent']['id']);
        $pricedItems = [];
        foreach ($planned['local_estimates'] as $localEstimate) {
            foreach ($localEstimate['sections'] as $section) {
                foreach ($section['work_items'] as $workItem) {
                    if (($workItem['item_type'] ?? null) === 'priced_work') {
                        $pricedItems[] = $workItem;
                    }
                }
            }
        }
        self::assertNotEmpty($pricedItems);
        foreach ($pricedItems as $workItem) {
            self::assertArrayHasKey('candidate_id', $workItem['composition_intent']);
            self::assertMatchesRegularExpression(
                '/^work:[a-f0-9]{64}$/D',
                $workItem['composition_intent']['candidate_id'],
            );
            self::assertArrayNotHasKey('price', $workItem['composition_intent']);
        }
        self::assertArrayNotHasKey('work_composition_advice', $planned['package_plan']);
        $extracted = $state->priorOutputs($seed)->payload(ProcessingStage::ExtractQuantities);
        $floorAreaRows = array_values(array_filter(
            $extracted['building_quantities']['quantities'] ?? [],
            static fn (mixed $quantity): bool => is_array($quantity) && ($quantity['key'] ?? null) === 'floor_area',
        ));
        if ($roomFloorCase) {
            self::assertSame([], $floorAreaRows);
            self::assertSame(['room:1', 'room:2'], $extracted['stage6_generation_context']['room_floor_entity_ids']);
            $roomFloors = [];
            foreach ($pricedItems as $item) {
                $work = $item['metadata']['composition_work_key'] ?? null;
                if (! in_array($work, ['rough.floor', 'finish.floor'], true)) {
                    continue;
                }
                self::assertNotEmpty($item['quantity_evidence_id']);
                self::assertNotEmpty($item['quantity_evidence_fingerprint']);
                self::assertSame($base, $item['quantity_evidence_source_version']);
                self::assertSame('evidenced', $item['quantity_evidence']['source']);
                self::assertSame($item['entity_id'], $item['quantity_evidence']['formula_inputs']['entity_id']);
                $roomFloors[$work][$item['entity_id']] = (string) $item['quantity'];
            }
            self::assertSame(['room:1' => '76', 'room:2' => '4'], $roomFloors['rough.floor']);
            self::assertSame($roomFloors['rough.floor'], $roomFloors['finish.floor']);

            return;
        }
        if ($knownPricedPart) {
            self::assertSame([], $floorAreaRows);
        } else {
            self::assertNotEmpty($floorAreaRows);
            self::assertSame('80', $floorAreaRows[0]['amount']);
        }
        $builtDraft = $state->priorOutputs($seed)->payload(ProcessingStage::BuildDraft)['draft'];
        self::assertSame($planned['estimate_composition'], $builtDraft['estimate_composition']);

        $payload = $state->priorOutputs($seed)->payload(ProcessingStage::ValidateDraft);
        self::assertArrayHasKey('quality_summary', $payload['draft']);
        self::assertSame($builtDraft['stage6_candidate_rows'], $payload['draft']['stage6_candidate_rows']);
        self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->verifyArtifact($payload['draft']));
        $accepted = [];
        foreach ($payload['draft']['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                array_push($accepted, ...$section['work_items']);
            }
        }
        if ($knownPricedPart) {
            self::assertCount(1, $accepted);
            self::assertSame('Монтаж плинтусов', $accepted[0]['name']);
            self::assertSame('10', (string) $accepted[0]['quantity']);
            self::assertSame('35', $accepted[0]['total_cost']);
            self::assertSame('accepted', $payload['draft']['independent_audit']['status']);
            self::assertSame(0, $payload['draft']['independent_audit']['correction_cycles']);
            self::assertSame(1, $auditor->calls);
            self::assertNotSame('full_confirmed_scope', $payload['draft']['completeness']['status']);
        } else {
            self::assertNotEmpty($payload['draft']['stage6_candidate_rows']);
            self::assertSame('review_required', $payload['draft']['stage6_status']);
            self::assertFalse($payload['draft']['is_complete']);
            self::assertTrue($payload['requires_review']);
            self::assertSame([], $accepted);
            self::assertArrayNotHasKey('independent_audit', $payload['draft']);
            self::assertSame(0, $auditor->calls);
        }
    }

    public static function pinnedPriceCases(): array
    {
        return ['candidate_only' => [false], 'known_priced_part' => [true], 'universal_room_quantities' => [false, true]];
    }
}
