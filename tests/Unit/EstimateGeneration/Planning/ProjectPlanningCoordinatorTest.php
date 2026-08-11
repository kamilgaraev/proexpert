<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningCoordinator;
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
            $this->fact('fact:roof-slope', 'roof_slope_degrees', '28'),
            $this->fact('fact:roof-geometry', 'roof_geometry', 'simple_gable'),
        ];
        $repository->saveSourceModel([], $facts, [$evidence]);
        $catalogData = require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php';
        $coordinator = $this->coordinator($repository, $catalogData);

        $first = $coordinator->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []));
        $replayed = $coordinator->refresh(10, 20, 30, new OrganizationPreferenceContext(10, []));

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

    private function fact(
        string $id,
        string $type,
        mixed $value,
        string $origin = 'document',
        string $status = 'confirmed',
        array $evidenceIds = ['evidence:1'],
    ): Fact {
        return new Fact(
            id: $id,
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            sourceVersion: self::SOURCE_VERSION,
            entityId: 'entity:roof',
            type: $type,
            value: $value,
            unit: null,
            confidence: $status === 'confirmed' ? 1.0 : 0.0,
            origin: $origin,
            status: $status,
            evidenceIds: $evidenceIds,
        );
    }
}
