<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingResult;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class ProjectPlanningCoordinatorTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_recommendations_are_persisted_replayed_and_catalog_versioned_on_exact_snapshot(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $evidence = new Evidence('evidence:1', 10, 20, 30, self::SOURCE_VERSION, 'artifact:roof', 'drawing', page: 1);
        $facts = [
            $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved', []),
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '28', unit: 'degree'),
            $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
        ];
        $repository->saveSourceModel([], $facts, [$evidence]);
        $catalogData = require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php';
        $coordinator = $this->coordinator($repository, $catalogData);

        $understanding = $this->readyUnderstanding($repository);
        $first = $coordinator->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);
        $replayed = $coordinator->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);

        self::assertCount(1, $first->recommendations);
        self::assertSame($first->fingerprint(), $replayed->fingerprint());
        self::assertSame(1, $repository->technologyPlanningWriteCount);
        self::assertCount(1, $repository->technologyPlanningHistory);
        self::assertSame($first->catalogVersion, $repository->currentTechnologyRecommendations(10, 20, 30)['catalog_version']);

        $catalogData['version'] = '2026.08.11-v2';
        $changed = $this->coordinator($repository, $catalogData)->refresh(
            10,
            20,
            30,
            new OrganizationPreferenceContext(10, []),
            $understanding,
        );

        self::assertSame('2026.08.11-v2', $changed->catalogVersion);
        self::assertSame(2, $repository->technologyPlanningWriteCount);
        self::assertCount(2, $repository->technologyPlanningHistory);
        self::assertCount(1, array_filter($repository->technologyPlanningHistory, static fn (array $run): bool => $run['is_current']));

        $repository->invalidateSourceVersion(
            10,
            20,
            30,
            self::SOURCE_VERSION,
            'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
        );
        self::assertNull($repository->currentTechnologyRecommendations(10, 20, 30));
    }

    public function test_planning_is_blocked_without_successful_current_stage_four_projection(): void
    {
        foreach ([
            ProjectUnderstandingResult::unresolved(['budget_exceeded']),
            ProjectUnderstandingResult::unresolved(['insufficient_evidence']),
            ProjectUnderstandingResult::stale(['stale_snapshot']),
            ProjectUnderstandingResult::unresolved(['provider_unavailable']),
        ] as $understanding) {
            $repository = new InMemoryProjectModelRepository;
            $repository->saveSourceModel([], [
                $this->fact('fact:roof-material', 'roof_covering_system', null, 'unresolved', 'unresolved', []),
            ], []);

            $result = $this->coordinator(
                $repository,
                require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
            )->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);

            self::assertFalse($result->isReadyForCompleteness());
            self::assertSame(0, $repository->technologyPlanningWriteCount);
            self::assertNull($repository->currentTechnologyRecommendations(10, 20, 30));
        }
    }

    public function test_planning_rejects_missing_or_non_exact_current_understanding_projection(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $repository->saveSourceModel([], [
            $this->fact('fact:roof-material', 'roof_covering_system', null, 'unresolved', 'unresolved', []),
        ], []);
        $capture = $repository->snapshotForPlanning(10, 20, 30, 100);
        $declaredReady = ProjectUnderstandingResult::current(self::SOURCE_VERSION, $capture['token'], [], [], [], 0);

        $missing = $this->coordinator(
            $repository,
            require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
        )->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $declaredReady);

        self::assertFalse($missing->isReadyForCompleteness());
        self::assertSame(0, $repository->technologyPlanningWriteCount);

        $repository->replaceUnderstanding(10, 20, 30, self::SOURCE_VERSION, $capture['token'], [], [], [], [], 0);
        $repository->removeProjection('fact:roof-material');
        $stale = $this->coordinator(
            $repository,
            require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
        )->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $declaredReady);

        self::assertFalse($stale->isReadyForCompleteness());
        self::assertSame(0, $repository->technologyPlanningWriteCount);
    }

    public function test_replay_never_reactivates_a_run_when_decision_changes_between_precheck_and_lock(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $repository->saveSourceModel([], [
            $this->fact('fact:roof-material', 'roof_covering_system', null, 'unresolved', 'unresolved', []),
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '28', unit: 'degree'),
        ], [new Evidence('evidence:1', 10, 20, 30, self::SOURCE_VERSION, 'artifact:roof', 'drawing', page: 1)]);
        $understanding = $this->readyUnderstanding($repository);
        $coordinator = $this->coordinator(
            $repository,
            require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
        );
        $coordinator->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);
        $selected = $repository->fact(10, 20, 30, 'fact:roof-type');
        self::assertInstanceOf(Fact::class, $selected);
        $repository->beforeTechnologyReplayLock = static function () use ($repository, $selected): void {
            $repository->applyDecision(new Decision(
                'decision:concurrent', 10, 20, 30, self::SOURCE_VERSION, 'fact', 'fact:roof-type',
                'fact:roof-type', 'user', '42', 'Параллельное решение', 2,
            ), $selected);
        };

        $result = $coordinator->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);

        self::assertFalse($result->isReadyForCompleteness());
        self::assertNull($repository->currentTechnologyRecommendations(10, 20, 30));
        self::assertSame(1, $repository->technologyPlanningWriteCount);
    }

    public function test_older_catalog_run_cannot_replace_the_latest_catalog_projection(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $repository->saveSourceModel([], [
            $this->fact('fact:roof-material', 'roof_covering_system', null, 'unresolved', 'unresolved', []),
            $this->fact('fact:roof-type', 'roof_type', 'pitched'),
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '28', unit: 'degree'),
        ], [new Evidence('evidence:1', 10, 20, 30, self::SOURCE_VERSION, 'artifact:roof', 'drawing', page: 1)]);
        $understanding = $this->readyUnderstanding($repository);
        $v1 = require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php';
        $v2 = $v1;
        $v2['version'] = '2026.08.11-v2';
        $old = $this->coordinator($repository, $v1);
        $latest = $this->coordinator($repository, $v2);
        $old->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);
        $latest->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);

        $stale = $old->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);

        self::assertFalse($stale->isReadyForCompleteness());
        self::assertSame('2026.08.11-v2', $repository->currentTechnologyRecommendations(10, 20, 30)['catalog_version']);
    }

    public function test_aliases_are_deduplicated_per_roof_entity_and_unrelated_materials_are_ignored(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $repository->saveSourceModel([], [
            $this->fact('fact:roof-material', 'material', null, 'unresolved', 'unresolved', [], 'entity:roof-1'),
            $this->fact('fact:roof-material-name', 'material_name', null, 'unresolved', 'unresolved', [], 'entity:roof-1'),
            $this->fact('fact:roof-type-1', 'roof_type', 'pitched', entityId: 'entity:roof-1'),
            $this->fact('fact:roof-material-2', 'roof_covering_system', null, 'unresolved', 'unresolved', [], 'entity:roof-2'),
            $this->fact('fact:roof-type-2', 'roof_type', 'pitched', entityId: 'entity:roof-2'),
            $this->fact('fact:facade-material', 'material', null, 'unresolved', 'unresolved', [], 'entity:facade'),
            $this->fact('fact:facade-type', 'facade_type', 'ventilated', entityId: 'entity:facade'),
        ], [new Evidence('evidence:1', 10, 20, 30, self::SOURCE_VERSION, 'artifact:project', 'drawing', page: 1)]);
        $understanding = $this->readyUnderstanding($repository);

        $result = $this->coordinator(
            $repository,
            require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php',
        )->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []), $understanding);

        self::assertCount(2, $result->recommendations);
        self::assertCount(2, array_unique(array_map(static fn ($item): string => $item->decisionKey, $result->recommendations)));
        self::assertNotContains('fact:facade-material', array_map(static fn ($item): string => $item->targetFactId, $result->recommendations));
    }

    private function coordinator(InMemoryProjectModelRepository $repository, array $catalogData): ProjectPlanningCoordinator
    {
        $catalog = TechnologySystemCatalog::fromArray($catalogData);

        return new ProjectPlanningCoordinator(
            $repository,
            new TechnologyRecommendationService($catalog, static fn (string $key): string => $key),
            $catalog,
            maxFacts: 100,
            maxRecommendations: 10,
        );
    }

    private function readyUnderstanding(InMemoryProjectModelRepository $repository): ProjectUnderstandingResult
    {
        $capture = $repository->snapshotForPlanning(10, 20, 30, 100);
        self::assertTrue($repository->replaceUnderstanding(
            10,
            20,
            30,
            self::SOURCE_VERSION,
            $capture['token'],
            [],
            [],
            [],
            [],
            0,
        ));

        return ProjectUnderstandingResult::current(self::SOURCE_VERSION, $capture['token'], [], [], [], 0);
    }

    private function fact(
        string $id,
        string $type,
        mixed $value,
        string $origin = 'document',
        string $status = 'confirmed',
        array $evidenceIds = ['evidence:1'],
        string $entityId = 'entity:roof',
        ?string $unit = null,
    ): Fact {
        return new Fact(
            id: $id,
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            sourceVersion: self::SOURCE_VERSION,
            entityId: $entityId,
            type: $type,
            value: $value,
            unit: $unit,
            confidence: $status === 'confirmed' ? 1.0 : 0.0,
            origin: $origin,
            status: $status,
            evidenceIds: $evidenceIds,
        );
    }
}
