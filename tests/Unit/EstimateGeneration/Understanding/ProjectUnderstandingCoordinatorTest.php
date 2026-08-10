<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitratorFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ExpectedArbitrationFailure;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class ProjectUnderstandingCoordinatorTest extends TestCase
{
    #[Test]
    #[DataProvider('deterministicCases')]
    public function mandatory_cross_document_scenarios_run_through_coordinator_and_persist(
        string $leftRole,
        string $rightRole,
        string $attribute,
        mixed $value,
        string $strategy,
        string $type,
    ): void {
        $models = new InMemoryProjectModelRepository;
        $factory = new RecordingArbitratorFactory;
        $this->seedPair($models, $leftRole, $rightRole, $attribute, $value, $type);
        $coordinator = $this->coordinator($models, $factory);

        $first = $coordinator->refresh(1, 2, 3, $this->token(), 1);
        $second = $coordinator->refresh(1, 2, 3, $this->token(), 2);

        self::assertCount(1, $first->links);
        self::assertSame($strategy, $first->links[0]['strategy']);
        self::assertSame($first->links, $second->links);
        self::assertSame(0, $factory->calls);
        self::assertSame($first->links, $models->currentUnderstanding(1, 2, 3)['links']);
        self::assertNull($models->currentUnderstanding(99, 2, 3));
    }

    public static function deterministicCases(): array
    {
        return [
            'room' => ['plan', 'room_schedule', 'room_number', '101', 'room_number', 'room'],
            'axes' => ['plan', 'section', 'axes', ['A', '1'], 'axes', 'dimension'],
            'equipment' => ['equipment_plan', 'equipment_specification', 'position', 'E-12', 'equipment_position', 'equipment'],
            'facade' => ['facade', 'finish_schedule', 'facade_zone', 'F-1', 'facade_material', 'material'],
        ];
    }

    #[Test]
    public function ambiguous_group_uses_one_bounded_call_and_persists_only_a_suggestion(): void
    {
        $models = new InMemoryProjectModelRepository;
        $factory = new RecordingArbitratorFactory('fact:right-2');
        $this->seedPair($models, 'plan', 'room_schedule', 'room_number', '101', 'room');
        $this->appendRight($models, 'entity:right-2', 'fact:right-2', 'evidence:3');

        $result = $this->coordinator($models, $factory)->refresh(1, 2, 3, $this->token(), 1);

        self::assertSame(1, $factory->calls);
        self::assertSame('suggested', $result->links[0]['status']);
        self::assertNotSame('confirmed', $result->links[0]['status']);
    }

    #[Test]
    public function expected_provider_failure_is_persisted_as_unresolved_while_invariants_remain_fail_fast(): void
    {
        $models = new InMemoryProjectModelRepository;
        $factory = new RecordingArbitratorFactory(fail: true);
        $this->seedPair($models, 'plan', 'room_schedule', 'room_number', '101', 'room');
        $this->appendRight($models, 'entity:right-2', 'fact:right-2', 'evidence:3');

        $result = $this->coordinator($models, $factory)->refresh(1, 2, 3, $this->token(), 1);

        self::assertSame([], $result->links);
        self::assertNotSame([], $result->limitations);
        self::assertNotSame([], $result->questions);
        self::assertNotNull($models->currentUnderstanding(1, 2, 3));
    }

    #[Test]
    public function global_budget_stops_before_provider_and_source_invalidation_clears_current_result(): void
    {
        $models = new InMemoryProjectModelRepository;
        $factory = new RecordingArbitratorFactory('fact:right-2');
        $this->seedPair($models, 'plan', 'room_schedule', 'room_number', '101', 'room');
        $models->saveSourceModel(
            [
                new Entity('entity:axis-left', 1, 2, 3, $this->modelSource(), 'dimension', 'entity:axis-left', ['document_role' => 'plan', 'axes' => ['A', '1']]),
                new Entity('entity:axis-right', 1, 2, 3, $this->modelSource(), 'dimension', 'entity:axis-right', ['document_role' => 'section', 'axes' => ['A', '1']]),
            ],
            [
                $this->fact('fact:axis-left', 'entity:axis-left', 'evidence:3'),
                $this->fact('fact:axis-right', 'entity:axis-right', 'evidence:4'),
            ],
            [$this->evidence('evidence:3'), $this->evidence('evidence:4')],
        );
        $budget = new ProjectUnderstandingBudget(10, 10, 3, 2, 10, 10, 10, 100_000);

        $result = $this->coordinator($models, $factory, $budget)->refresh(1, 2, 3, $this->token(), 1);

        self::assertSame([], $result->links);
        self::assertSame(0, $factory->calls);
        self::assertSame(11, $models->lastSnapshotFactLimit);
        self::assertNotSame([], $result->limitations);
        $models->invalidateSourceVersion(1, 2, 3, $this->evidenceSource(), 'sha256:'.str_repeat('c', 64));
        self::assertNull($models->currentUnderstanding(1, 2, 3));
        self::assertSame([], $models->currentFacts(1, 2, 3));
    }

    private function coordinator(InMemoryProjectModelRepository $models, RecordingArbitratorFactory $factory, ?ProjectUnderstandingBudget $budget = null): ProjectUnderstandingCoordinator
    {
        $translator = static fn (string $key, array $replace): string => strtr($key, $replace);

        return new ProjectUnderstandingCoordinator(
            $models,
            new TargetedConflictResolver($translator),
            $factory,
            $budget ?? ProjectUnderstandingBudget::defaults(),
        );
    }

    private function seedPair(InMemoryProjectModelRepository $models, string $leftRole, string $rightRole, string $attribute, mixed $value, string $type): void
    {
        $source = $this->modelSource();
        $entities = [
            new Entity('entity:left', 1, 2, 3, $source, $type, 'entity:left', ['document_role' => $leftRole, $attribute => $value]),
            new Entity('entity:right', 1, 2, 3, $source, $type, 'entity:right', ['document_role' => $rightRole, $attribute => $value]),
        ];
        $evidence = [$this->evidence('evidence:1'), $this->evidence('evidence:2')];
        $facts = [
            $this->fact('fact:left', 'entity:left', 'evidence:1'),
            $this->fact('fact:right', 'entity:right', 'evidence:2'),
        ];
        $models->saveSourceModel($entities, $facts, $evidence);
    }

    private function appendRight(InMemoryProjectModelRepository $models, string $entityId, string $factId, string $evidenceId): void
    {
        $models->saveSourceModel(
            [new Entity($entityId, 1, 2, 3, $this->modelSource(), 'room', $entityId, ['document_role' => 'room_schedule', 'room_number' => '101'])],
            [$this->fact($factId, $entityId, $evidenceId)],
            [$this->evidence($evidenceId)],
        );
    }

    private function fact(string $id, string $entityId, string $evidenceId): Fact
    {
        return new Fact($id, 1, 2, 3, $this->modelSource(), $entityId, 'area', '18.4', 'm2', 0.95, 'document', 'confirmed', [$evidenceId]);
    }

    private function evidence(string $id): Evidence
    {
        return new Evidence($id, 1, 2, 3, $this->evidenceSource(), 'document:1', 'document_unit', 1, null, 'node:'.$id);
    }

    private function modelSource(): string
    {
        return 'sha256:'.str_repeat('a', 64);
    }

    private function evidenceSource(): string
    {
        return 'sha256:'.str_repeat('b', 64);
    }

    private function token(): string
    {
        return '123e4567-e89b-42d3-a456-426614174000';
    }
}

final class RecordingArbitratorFactory implements CrossDocumentFactArbitrator, CrossDocumentFactArbitratorFactory
{
    public int $calls = 0;

    public function __construct(
        private readonly ?string $selectedFactId = null,
        private readonly bool $fail = false,
    ) {}

    public function create(int $organizationId, int $projectId, int $sessionId, string $checkpointClaimToken, int $logicalAttempt): CrossDocumentFactArbitrator
    {
        return $this;
    }

    public function arbitrate(string $operationIdentity, array $payload, array $scope): array
    {
        $this->calls++;
        if ($this->fail) {
            throw new ExpectedArbitrationFailure('timeout');
        }

        return ['status' => 'suggested', 'selected_fact_id' => $this->selectedFactId, 'reason' => 'matching_context'];
    }
}
