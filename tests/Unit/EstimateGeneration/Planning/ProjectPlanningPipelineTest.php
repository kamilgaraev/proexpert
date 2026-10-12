<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitratorFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class ProjectPlanningPipelineTest extends TestCase
{
    private const SOURCE = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private \Illuminate\Container\Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = \Illuminate\Container\Container::getInstance();
        $container = new \Illuminate\Container\Container;
        $container->instance('config', new \Illuminate\Config\Repository(['estimate-generation' => ['universal_enabled' => false]]));
        \Illuminate\Container\Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_decision_reanalysis_runs_server_planning_and_completeness_without_any_model(): void
    {
        $repository = $this->repository();
        $trigger = new \App\BusinessModules\Addons\EstimateGeneration\Application\Planning\SynchronousPlanningReanalysisTrigger($this->pipeline($repository, true));
        $trigger->trigger(30, new \App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext(10, 20, 40, 'technology-choice:12345678'));
        self::assertSame(1, $repository->technologyPlanningWriteCount);
        self::assertSame(1, $repository->completenessWriteCount);
        self::assertSame(0, $repository->currentUnderstanding(10, 20, 30)['provider_calls']);
    }

    public function test_successful_current_understanding_runs_recommendations_then_completeness_once(): void
    {
        $repository = $this->repository();
        $pipeline = $this->pipeline($repository);

        $first = $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174000', 1);
        $replay = $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174000', 2);

        self::assertSame([], $first->limitations);
        self::assertTrue($first->isReadyForCompleteness());
        self::assertSame($first->fingerprint(), $replay->fingerprint());
        self::assertSame(1, $repository->technologyPlanningWriteCount);
        self::assertSame(1, $repository->completenessWriteCount);
    }

    public function test_blocked_stage_four_never_persists_planning_or_completeness_and_recovery_runs_once(): void
    {
        $repository = $this->repository();
        $repository->understandingWithinBudget = false;
        $pipeline = $this->pipeline($repository);

        $blocked = $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174000', 1);

        self::assertFalse($blocked->isReadyForCompleteness());
        self::assertSame(['budget_exceeded'], $blocked->limitations);
        self::assertSame(0, $repository->technologyPlanningWriteCount);
        self::assertSame(0, $repository->completenessWriteCount);

        $repository->understandingWithinBudget = true;
        $recovered = $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174000', 2);
        $replayed = $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174000', 3);

        self::assertSame([], $recovered->limitations);
        self::assertTrue($recovered->isReadyForCompleteness());
        self::assertSame($recovered->fingerprint(), $replayed->fingerprint());
        self::assertSame(1, $repository->technologyPlanningWriteCount);
        self::assertSame(1, $repository->completenessWriteCount);
    }

    public function test_empty_stage_four_snapshot_returns_stable_blocking_code_without_stage_five_writes(): void
    {
        $repository = new InMemoryProjectModelRepository;

        $blocked = $this->pipeline($repository)->refresh(
            10,
            20,
            30,
            '123e4567-e89b-42d3-a456-426614174020',
            1,
        );

        self::assertFalse($blocked->isReadyForCompleteness());
        self::assertSame(['empty_facts'], $blocked->limitations);
        self::assertSame(0, $repository->technologyPlanningWriteCount);
        self::assertSame(0, $repository->completenessWriteCount);
    }

    private function repository(): InMemoryProjectModelRepository
    {
        $repository = new InMemoryProjectModelRepository;
        $repository->saveSourceModel([
            new Entity('entity:roof', 10, 20, 30, self::SOURCE, 'material', 'roof:main', ['document_role' => 'roof_plan']),
            new Entity('entity:room-plan', 10, 20, 30, self::SOURCE, 'room', 'room:plan', ['document_role' => 'plan', 'room_number' => '101']),
            new Entity('entity:room-schedule', 10, 20, 30, self::SOURCE, 'room', 'room:schedule', ['document_role' => 'room_schedule', 'room_number' => '101']),
        ], [
            new Fact('fact:roof-material', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_covering_system', null, null, 0, 'unresolved', 'unresolved', []),
            new Fact('fact:roof-type', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_type', 'pitched', null, 1, 'document', 'confirmed', ['evidence:1']),
            new Fact('fact:roof-slope', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_slope_degrees', '28', 'degree', 1, 'document', 'confirmed', ['evidence:1']),
            new Fact('fact:roof-area', 10, 20, 30, self::SOURCE, 'entity:roof', 'roof_area', '100', 'm2', 1, 'document', 'confirmed', ['evidence:1']),
            new Fact('fact:room-plan-area', 10, 20, 30, self::SOURCE, 'entity:room-plan', 'area', '20', 'm2', 1, 'document', 'confirmed', ['evidence:2']),
            new Fact('fact:room-schedule-area', 10, 20, 30, self::SOURCE, 'entity:room-schedule', 'area', '20', 'm2', 1, 'document', 'confirmed', ['evidence:3']),
        ], [
            new Evidence('evidence:1', 10, 20, 30, self::SOURCE, 'artifact:roof', 'drawing', page: 1),
            new Evidence('evidence:2', 10, 20, 30, self::SOURCE, 'artifact:plan', 'drawing', page: 1),
            new Evidence('evidence:3', 10, 20, 30, self::SOURCE, 'artifact:schedule', 'drawing', page: 1),
        ]);

        return $repository;
    }

    private function pipeline(InMemoryProjectModelRepository $repository, bool $serverOnly = false): ProjectPlanningPipeline
    {
        $translator = static fn (string $key, array $replace = []): string => strtr($key, $replace);
        $technology = TechnologySystemCatalog::fromArray(
            require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
        );
        $rules = CompletenessRuleCatalog::fromArray(
            require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php',
        );
        $arbitrators = new PipelineNoopArbitratorFactory;
        $synthesis = new \Tests\Support\EstimateGeneration\PassthroughProjectSynthesisRunner;
        $allowance = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\EloquentProjectSynthesisAllowance;
        if ($serverOnly) {
            $arbitrators = $this->createMock(CrossDocumentFactArbitratorFactory::class);
            $arbitrators->expects(self::never())->method('create');
            $synthesis = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisRunner::class);
            $synthesis->expects(self::never())->method('run');
            $allowance = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisAllowance::class);
            $allowance->expects(self::never())->method('partLimit');
        }

        return new ProjectPlanningPipeline(
            new ProjectUnderstandingCoordinator(
                $repository,
                new TargetedConflictResolver($translator),
                $arbitrators,
                ProjectUnderstandingBudget::defaults(),
                $synthesis,
                $allowance,
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
                new ProjectCompletenessAnalyzer($rules, new TechnologyWorkPackageBuilder(static fn (string $key): string => 'Человекочитаемое название'), 50, 50, 200),
                $rules,
                100,
            ),
        );
    }
}

final class PipelineNoopArbitratorFactory implements CrossDocumentFactArbitrator, CrossDocumentFactArbitratorFactory
{
    public function create(int $organizationId, int $projectId, int $sessionId, string $checkpointClaimToken, int $logicalAttempt): CrossDocumentFactArbitrator
    {
        return $this;
    }

    public function arbitrate(string $operationIdentity, array $payload, array $scope): array
    {
        return ['status' => 'unresolved', 'selected_fact_id' => null, 'reason' => 'not_required'];
    }
}
