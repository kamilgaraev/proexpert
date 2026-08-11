<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitratorFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class Stage5PlanningWorkflowRegressionTest extends TestCase
{
    private const SOURCE = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Container $previousContainer;

    private PlanningWorkflowRecordingDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $this->dispatcher = new PlanningWorkflowRecordingDispatcher;
        $container->instance(Dispatcher::class, $this->dispatcher);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_reconcile_persists_stage_four_blocker_and_recovers_without_duplicate_dispatch(): void
    {
        $repository = $this->repository();
        $repository->understandingWithinBudget = false;
        $session = $this->makeSession();
        $store = new PlanningWorkflowSessionStateStore($session);
        $advance = new AdvanceEstimateGeneration(
            new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, $store),
        );
        $readiness = $this->createMock(DocumentGenerationReadinessService::class);
        $readiness->method('evaluate')->willReturn([
            'can_generate' => true,
            'summary' => ['pending_count' => 0, 'action_required_count' => 0],
        ]);
        $reconcile = new ReconcileEstimateGenerationDocuments(
            $advance,
            $readiness,
            $this->pipeline($repository),
        );

        $blocked = $reconcile->reconcile($session);

        self::assertSame(EstimateGenerationStatus::InputReviewRequired, $blocked->status);
        self::assertSame('project_planning_blocked', $blocked->failure_code);
        self::assertSame(
            ['status' => 'blocked', 'limitations' => ['budget_exceeded']],
            $blocked->input_payload['planning_review'],
        );
        self::assertSame(0, $repository->technologyPlanningWriteCount);
        self::assertSame(0, $repository->completenessWriteCount);
        self::assertSame([], $this->dispatcher->jobs);

        $quota = (new ReflectionClass(AiEstimateQuotaService::class))->newInstanceWithoutConstructor();
        $snapshot = (new BuildSessionSnapshot(
            $quota,
            static fn (string $key): string => 'Перевод: '.$key,
        ))->handle(
            $blocked,
            [],
            ['can_generate' => false, 'can_apply' => false],
        );
        self::assertSame('budget_exceeded', $snapshot->blockingIssues[0]['code']);
        self::assertNotSame('estimate_generation.project_model.operation_limit', $snapshot->blockingIssues[0]['message']);

        $repository->understandingWithinBudget = true;
        $recovering = $reconcile->changed($blocked);
        self::assertSame(EstimateGenerationStatus::ProcessingDocuments, $recovering->status);
        self::assertArrayNotHasKey('planning_review', $recovering->input_payload);
        self::assertTrue($recovering->input_payload['generation_requested']);

        $recovered = $reconcile->reconcile($recovering);
        self::assertSame(EstimateGenerationStatus::Generating, $recovered->status);
        self::assertNull($recovered->failure_code);
        self::assertSame(1, $repository->technologyPlanningWriteCount);
        self::assertSame(1, $repository->completenessWriteCount);
        self::assertCount(1, $this->dispatcher->jobs);
        self::assertInstanceOf(GenerateEstimateDraftJob::class, $this->dispatcher->jobs[0]);

        $version = $recovered->state_version;
        $replayed = $reconcile->reconcile($recovered);
        self::assertSame($version, $replayed->state_version);
        self::assertCount(1, $this->dispatcher->jobs);
    }

    private function makeSession(): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession([
            'organization_id' => 10,
            'project_id' => 20,
            'user_id' => 40,
            'status' => EstimateGenerationStatus::ProcessingDocuments,
            'processing_stage' => 'processing_documents',
            'processing_progress' => 35,
            'state_version' => 1,
            'input_payload' => [
                'description' => 'Смета кровли',
                'generation_requested' => true,
            ],
        ]);
        $session->id = 30;
        $session->setAttribute('ai_estimate_quota_snapshot', [
            'included' => 10,
            'purchased' => 0,
            'used' => 0,
            'available' => 10,
            'reservation_status' => null,
        ]);

        return $session;
    }

    private function repository(): InMemoryProjectModelRepository
    {
        $repository = new InMemoryProjectModelRepository;
        $repository->saveSourceModel([
            new Entity('entity:roof', 10, 20, 30, self::SOURCE, 'material', 'roof:main', ['document_role' => 'roof_plan']),
        ], [
            new Fact('fact:roof-material', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_covering_system', null, null, 0, 'unresolved', 'unresolved', []),
            new Fact('fact:roof-type', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_type', 'pitched', null, 1, 'document', 'confirmed', ['evidence:1']),
            new Fact('fact:roof-slope', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_slope_degrees', '28', 'degree', 1, 'document', 'confirmed', ['evidence:1']),
            new Fact('fact:roof-area', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_area', '100', 'm2', 1, 'document', 'confirmed', ['evidence:1']),
        ], [
            new Evidence('evidence:1', 10, 20, 30, self::SOURCE, 'artifact:roof', 'drawing', page: 1),
        ]);

        return $repository;
    }

    private function pipeline(InMemoryProjectModelRepository $repository): ProjectPlanningPipeline
    {
        $translator = static fn (string $key, array $replace = []): string => strtr($key, $replace);
        $technology = TechnologySystemCatalog::fromArray(
            require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
        );
        $rules = CompletenessRuleCatalog::fromArray(
            require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php',
        );

        return new ProjectPlanningPipeline(
            new ProjectUnderstandingCoordinator(
                $repository,
                new TargetedConflictResolver($translator),
                new PlanningWorkflowNoopArbitratorFactory,
                ProjectUnderstandingBudget::defaults(),
            ),
            new ProjectPlanningCoordinator(
                $repository,
                new TechnologyRecommendationService($technology, $translator),
                $technology,
                100,
                10,
            ),
            new ProjectCompletenessCoordinator(
                $repository,
                new ProjectCompletenessAnalyzer(
                    $rules,
                    new TechnologyWorkPackageBuilder(static fn (string $key): string => 'Человекочитаемое название'),
                    50,
                    50,
                    200,
                ),
                $rules,
                100,
            ),
        );
    }
}

final class PlanningWorkflowSessionStateStore implements SessionStateStore
{
    public function __construct(private EstimateGenerationSession $session) {}

    public function create(array $attributes): EstimateGenerationSession
    {
        $this->session = new EstimateGenerationSession($attributes);

        return $this->session;
    }

    public function compareAndSet(
        EstimateGenerationSession $session,
        int $expectedVersion,
        EstimateGenerationStatus $status,
        array $attributes,
    ): EstimateGenerationSession {
        if ($session->getKey() !== $this->session->getKey() || $expectedVersion !== $this->session->state_version) {
            throw new StaleEstimateGenerationState((int) $session->getKey(), $expectedVersion);
        }

        $this->session->forceFill([
            ...$attributes,
            'status' => $status,
            'state_version' => $expectedVersion + 1,
        ]);

        return $this->session;
    }
}

final class PlanningWorkflowNoopArbitratorFactory implements CrossDocumentFactArbitrator, CrossDocumentFactArbitratorFactory
{
    public function create(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $checkpointClaimToken,
        int $logicalAttempt,
    ): CrossDocumentFactArbitrator {
        return $this;
    }

    public function arbitrate(string $operationIdentity, array $payload, array $scope): array
    {
        return ['status' => 'unresolved', 'selected_fact_id' => null, 'reason' => 'not_required'];
    }
}

final class PlanningWorkflowRecordingDispatcher implements Dispatcher
{
    public array $jobs = [];

    public function dispatch($command)
    {
        $this->jobs[] = $command;

        return $command;
    }

    public function dispatchSync($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function hasCommandHandler($command)
    {
        return false;
    }

    public function getCommandHandler($command)
    {
        return false;
    }

    public function pipeThrough(array $pipes)
    {
        return $this;
    }

    public function map(array $map)
    {
        return $this;
    }
}
