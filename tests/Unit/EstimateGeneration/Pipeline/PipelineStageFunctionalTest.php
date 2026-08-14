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
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
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
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;
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
    public function it_preserves_legacy_global_selection_when_candidate_id_map_is_explicitly_null(): void
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
                'candidate_ids_by_work_item' => null,
            ],
            'estimate_composition' => [
                'schema_version' => 1,
                'snapshot_token' => str_repeat('c', 64),
                'input_fingerprint' => str_repeat('d', 64),
                'intents_count' => 1,
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
                        throw new \LogicException('Legacy global selection must not search when the pinned catalog is empty.');
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
                    throw new \LogicException('Legacy global selection must not rerank when the pinned catalog is empty.');
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
    public function nine_real_stage_boundaries_consume_exact_typed_dependencies(): void
    {
        $gateway = new class implements GenerationPipelineDataGateway
        {
            public function manifest(PipelineContext $context): array
            {
                return ['base_input_version' => (string) $context->baseInputVersion, 'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null];
            }

            public function source(PipelineContext $context): array
            {
                return ['input' => [
                    'description' => 'Полное строительство одноэтажного жилого дома под ключ, включая отопление и канализацию',
                    'area' => 80,
                    'generation_mode' => 'ai_assisted',
                ], 'documents' => [], 'user_id' => 7, 'document_total_area' => [
                    'amount' => '80.000000',
                    'evidence_id' => 701,
                    'confidence' => 0.95,
                    'floor_count' => 1,
                ]];
            }
        };
        $matcher = $this->createMock(ResourceAssemblyService::class);
        $matcher->method('enrich')->willReturnCallback(static fn (array $items): array => $items);
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
            'evidence:room:length', 1, 2, 3, $base, 'artifact:plan', 'document', 1, null, 'room:length',
        );
        $widthEvidence = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence(
            'evidence:room:width', 1, 2, 3, $base, 'artifact:plan', 'document', 1, null, 'room:width',
        );
        $projectModels->saveSourceModel([$room], [
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact(
                'fact:room:length', 1, 2, 3, $base, $room->id, 'length', '10', 'm', 1.0,
                'document', 'confirmed', [$lengthEvidence->id],
            ),
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact(
                'fact:room:width', 1, 2, 3, $base, $room->id, 'width', '8', 'm', 1.0,
                'document', 'confirmed', [$widthEvidence->id],
            ),
        ], [$lengthEvidence, $widthEvidence]);
        $snapshotToken = $projectModels->snapshotForPlanning(1, 2, 3, 100)['token'];
        self::assertTrue($projectModels->replaceTechnologyRecommendations(
            1, 2, 3, $base, $snapshotToken, 'technology:v1', str_repeat('c', 64), [], [],
        ));
        $technologyPackage = new \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackage(
            'package:floor',
            [[
                'id' => 'work:floor',
                'label' => 'Устройство пола',
                'quantity_formula_id' => 'formula:floor',
                'norm_intent_id' => 'intent:floor',
            ]],
            [],
            [],
            [['id' => 'intent:floor', 'candidate_refs' => ['floor_installation']]],
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
        $stages = [
            new UnderstandDocumentsStage($gateway, $results),
            new UnderstandObjectStage(new ConstructionSemanticParser, $gateway, $results),
            new ExtractQuantitiesStage(
                new EstimateGenerationQuantityLearningEvidenceService,
                $results,
                $canonicalQuantities,
            ),
            new PlanWorkItemsStage(
                new \App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler(new PackagePlannerService, new EstimateDecompositionService, new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService), new NormativeContextPinResolver),
                $results,
                new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer(new \App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository),
                new RunEstimateComposer(
                    new InMemoryAiRoleRunRepository,
                    new class implements EstimateComposerModel
                    {
                        public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
                        {
                            $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

                            return ['work_intents' => array_map(static fn (array $candidate): array => [
                                'candidate_id' => $candidate['candidate_id'],
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
            new ResolvePricesStage(new EstimatePricingService, $results),
            new BuildDraftStage($results),
            new ValidateDraftStage(
                new EstimateValidationService,
                new DraftReadinessProjector,
                $results,
                new ApplyComposerCorrectionCycle(new RunEstimateAudit(
                    new InMemoryAiRoleRunRepository,
                    new class implements EstimateAuditModel
                    {
                        public function audit(EstimateAuditInput $input, callable $onAttemptStarted): array
                        {
                            $onAttemptStarted('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

                            return ['accepted' => true, 'findings' => []];
                        }
                    },
                    'test-model',
                )),
                new EstimateAuditInputFactory($projectModels, 10000),
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
        for ($invocation = 0; $invocation < 9; $invocation++) {
            $context = $resolver->next($seed);
            self::assertNotNull($context);
            if ($context->stage === ProcessingStage::ExtractQuantities) {
                self::assertNotNull($projectModels->currentTechnologyRecommendations(1, 2, 3), 'technology stale before stage 6');
                self::assertNotNull($projectModels->currentCompleteness(1, 2, 3), 'completeness stale before stage 6');
            }
            $result = $runner->runNext($context);
            if ($context->stage === ProcessingStage::ExtractQuantities) {
                self::assertNotEmpty($result?->transientData['stage6_generation_context']['work_packages'] ?? []);
            }
            self::assertSame($context->stage, $result?->stage);
        }
        self::assertNull($resolver->next($seed));
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
        self::assertSame('Устройство пола', $plannedItems[$floorPackageQuantity]['name']);
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
        self::assertNotEmpty($floorAreaRows);
        self::assertSame('80', $floorAreaRows[0]['amount']);

        $payload = $state->priorOutputs($seed)->payload(ProcessingStage::ValidateDraft);
        self::assertArrayHasKey('quality_summary', $payload['draft']);
        self::assertSame('accepted', $payload['draft']['independent_audit']['status']);
        self::assertSame(0, $payload['draft']['independent_audit']['correction_cycles']);
        self::assertSame([], $payload['draft']['audit_review_items']);
    }
}
