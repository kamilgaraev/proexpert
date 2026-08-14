<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitratorFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessExclusionDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class CompletenessExclusionDecisionServiceTest extends TestCase
{
    private const SOURCE = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function test_canonical_exclusion_remains_current_after_decision_and_real_reanalysis(): void
    {
        [$repository, $pipeline, $actor, $session, $context] = $this->fixture(true);
        $initial = $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174000', 1);
        self::assertTrue($initial->isReadyForCompleteness());
        $projection = $repository->currentCompleteness(10, 20, 30);
        self::assertNotNull($projection);
        $finding = $this->finding($projection, 'base_preparation');
        self::assertSame('proven_missing', $finding->status);

        $trigger = new class($pipeline) implements PlanningReanalysisTrigger
        {
            public int $calls = 0;

            public function __construct(private ProjectPlanningPipeline $pipeline) {}

            public function trigger(int $sessionId, ActorContext $context): void
            {
                $this->calls++;
                $this->pipeline->refresh(
                    $context->organizationId,
                    $context->projectId,
                    $sessionId,
                    '123e4567-e89b-42d3-a456-426614174001',
                    $this->calls,
                );
            }
        };
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::atLeastOnce())->method('can')->willReturn(true);
        $service = new CompletenessExclusionDecisionService(
            $repository,
            $authorization,
            $trigger,
            static fn (string $key): string => 'Доступ запрещён',
        );

        $decision = $service->exclude(
            $actor,
            $session,
            $context,
            (int) $projection['run_id'],
            $finding->stableKey,
            'Осознанно исключено пользователем',
        );

        self::assertSame(1, $trigger->calls);
        $selected = $repository->fact(10, 20, 30, (string) $decision->selectedFactId);
        self::assertSame('user_assumption', $selected?->origin);
        self::assertStringStartsWith('completeness_exclusion.', (string) $selected?->type);
        self::assertNotSame($projection['input_fingerprint'], $repository->snapshotForPlanning(10, 20, 30, 10001)['token']);
        $current = $repository->currentCompleteness(10, 20, 30);
        self::assertNotNull($current);
        self::assertSame('excluded', $this->finding($current, 'base_preparation')->status);
        self::assertSame($decision->id, $this->finding($current, 'base_preparation')->exclusionDecision['decision_id'] ?? null);

        $replayed = $service->exclude(
            $actor,
            $session,
            $context,
            (int) $projection['run_id'],
            $finding->stableKey,
            'Осознанно исключено пользователем',
        );
        self::assertSame($decision->id, $replayed->id);
        self::assertSame(1, $trigger->calls);
        self::assertCount(1, $repository->decisions);
    }

    public function test_real_pipeline_invalidates_exclusion_after_target_fact_replacement(): void
    {
        [$repository, $pipeline, $actor, $session, $context] = $this->fixture(true);
        $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174020', 1);
        $projection = $repository->currentCompleteness(10, 20, 30);
        self::assertNotNull($projection);
        $finding = $this->finding($projection, 'base_preparation');
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $service = new CompletenessExclusionDecisionService(
            $repository,
            $authorization,
            new class($pipeline) implements PlanningReanalysisTrigger
            {
                public function __construct(private ProjectPlanningPipeline $pipeline) {}

                public function trigger(int $sessionId, ActorContext $context): void
                {
                    $this->pipeline->refresh(
                        $context->organizationId,
                        $context->projectId,
                        $sessionId,
                        '123e4567-e89b-42d3-a456-426614174021',
                        2,
                    );
                }
            },
            static fn (string $key): string => 'Доступ запрещён',
        );
        $service->exclude(
            $actor,
            $session,
            $context,
            (int) $projection['run_id'],
            $finding->stableKey,
            'Осознанно исключено пользователем',
        );
        self::assertSame('excluded', $this->finding($repository->currentCompleteness(10, 20, 30), 'base_preparation')->status);

        $repository->saveSourceModel([], [new Fact(
            'fact:base-preparation-v2', 10, 20, 30, self::SOURCE, 'entity:project',
            'foundation_base_preparation', true, null, 1, 'document', 'confirmed', ['evidence:2'], 2,
            'fact:base-preparation',
        )], [new Evidence('evidence:2', 10, 20, 30, self::SOURCE, 'artifact:2', 'document', page: 2)]);
        $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174022', 3);

        $current = $repository->currentCompleteness(10, 20, 30);
        self::assertNotNull($current);
        self::assertSame('satisfied', $this->finding($current, 'base_preparation')->status);
        self::assertNull($this->finding($current, 'base_preparation')->exclusionDecision);
    }

    public function test_exclusion_boundary_rejects_payload_mismatch_stale_run_and_missing_abac(): void
    {
        [$repository, $pipeline, $actor, $session, $context] = $this->fixture(true);
        $pipeline->refresh(10, 20, 30, '123e4567-e89b-42d3-a456-426614174010', 1);
        $projection = $repository->currentCompleteness(10, 20, 30);
        self::assertNotNull($projection);
        $finding = $this->finding($projection, 'base_preparation');
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $service = new CompletenessExclusionDecisionService(
            $repository,
            $authorization,
            new class implements PlanningReanalysisTrigger
            {
                public function trigger(int $sessionId, ActorContext $context): void {}
            },
            static fn (string $key): string => 'Доступ запрещён',
        );

        try {
            $service->exclude($actor, $session, $context, 999, $finding->stableKey, 'Причина');
            self::fail('Stale completeness run was accepted.');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $repository->decisions);
        }

        $service->exclude($actor, $session, $context, (int) $projection['run_id'], $finding->stableKey, 'Причина');
        try {
            $service->exclude($actor, $session, $context, (int) $projection['run_id'], $finding->stableKey, 'Другая причина');
            self::fail('Idempotency payload mismatch was accepted.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $repository->decisions);
        }

        [, , $deniedActor, $deniedSession, $deniedContext] = $this->fixture(false);
        $deniedAuthorization = $this->createMock(AuthorizationService::class);
        $deniedAuthorization->method('can')->willReturn(false);
        $denied = new CompletenessExclusionDecisionService(
            $repository,
            $deniedAuthorization,
            new class implements PlanningReanalysisTrigger
            {
                public function trigger(int $sessionId, ActorContext $context): void {}
            },
            static fn (string $key): string => 'Доступ запрещён',
        );
        $this->expectException(AuthorizationException::class);
        $denied->exclude($deniedActor, $deniedSession, $deniedContext, (int) $projection['run_id'], $finding->stableKey, 'Причина');
    }

    private function fixture(bool $allowed): array
    {
        $repository = new InMemoryProjectModelRepository;
        $repository->saveSourceModel([
            new Entity('entity:project', 10, 20, 30, self::SOURCE, 'material', 'project:20'),
        ], [
            new Fact('fact:foundation-type', 10, 20, 30, self::SOURCE, 'entity:project', 'foundation_type', 'slab', null, 1, 'document', 'confirmed', ['evidence:1']),
            new Fact('fact:base-preparation', 10, 20, 30, self::SOURCE, 'entity:project', 'foundation_base_preparation', false, null, 1, 'document', 'confirmed', ['evidence:1']),
        ], [
            new Evidence('evidence:1', 10, 20, 30, self::SOURCE, 'artifact:1', 'document', page: 1),
        ]);
        $pipeline = $this->pipeline($repository);
        $actor = new User;
        $actor->setAttribute('id', 7);
        $actor->setAttribute('current_organization_id', $allowed ? 10 : 11);
        $session = new EstimateGenerationSession;
        $session->setAttribute('id', 30);
        $session->setAttribute('organization_id', 10);
        $session->setAttribute('project_id', 20);

        return [$repository, $pipeline, $actor, $session, new ActorContext(
            10,
            20,
            7,
            'completeness-exclusion-0001',
            self::SOURCE,
        )];
    }

    private function pipeline(InMemoryProjectModelRepository $repository): ProjectPlanningPipeline
    {
        $translator = static fn (string $key, array $replace = []): string => strtr($key, $replace);
        $technology = TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php');
        $rules = CompletenessRuleCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php');

        return new ProjectPlanningPipeline(
            new ProjectUnderstandingCoordinator(
                $repository,
                new TargetedConflictResolver($translator),
                new ExclusionNoopArbitratorFactory,
                ProjectUnderstandingBudget::defaults(),
                new \Tests\Support\EstimateGeneration\PassthroughProjectSynthesisRunner,
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

    private function finding(array $projection, string $ruleId): object
    {
        foreach ($projection['findings'] as $finding) {
            if ($finding->ruleId === $ruleId) {
                return $finding;
            }
        }

        self::fail('Finding not found: '.$ruleId);
    }
}

final class ExclusionNoopArbitratorFactory implements CrossDocumentFactArbitrator, CrossDocumentFactArbitratorFactory
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
