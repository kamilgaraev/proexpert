<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectCompletenessCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningResult;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class ProjectCompletenessCoordinatorTest extends TestCase
{
    private const SOURCE = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_projection_is_persisted_replayed_versioned_and_invalidated_with_source(): void
    {
        $repository = $this->repository();
        $data = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        $planning = $this->planning($repository);
        $coordinator = $this->coordinator($repository, $data);

        $first = $coordinator->refresh(10, 20, 30, $planning);
        $replay = $coordinator->refresh(10, 20, 30, $planning);

        self::assertSame($first->fingerprint(), $replay->fingerprint());
        self::assertSame(1, $repository->completenessWriteCount);
        self::assertCount(1, $repository->completenessHistory);
        self::assertCount(8, $repository->currentCompleteness(10, 20, 30)['findings']);

        $data['version'] = '2026.08.11-v2';
        $changed = $this->coordinator($repository, $data)->refresh(10, 20, 30, $planning);
        self::assertSame('2026.08.11-v2', $changed->ruleCatalogVersion);
        self::assertSame(2, $repository->completenessWriteCount);
        self::assertCount(2, $repository->completenessHistory);
        self::assertCount(1, array_filter($repository->completenessHistory, static fn (array $run): bool => $run['is_current']));

        $repository->invalidateSourceVersion(10, 20, 30, self::SOURCE, 'sha256:'.str_repeat('c', 64));
        self::assertNull($repository->currentCompleteness(10, 20, 30));
    }

    public function test_cross_tenant_and_stale_planning_snapshot_fail_closed(): void
    {
        $repository = $this->repository();
        $planning = $this->planning($repository);

        $this->expectException(InvalidArgumentException::class);
        $this->coordinator($repository, require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php')
            ->refresh(11, 20, 30, $planning);
    }

    public function test_historical_completeness_replay_rechecks_exact_snapshot_under_scope_lock(): void
    {
        $repository = $this->repository();
        $data = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        $planning = $this->planning($repository);
        $coordinator = $this->coordinator($repository, $data);
        $coordinator->refresh(10, 20, 30, $planning);
        $repository->beforeCompletenessReplayLock = function () use ($repository): void {
            $repository->saveSourceModel([], [new Fact(
                'fact:foundation:v2', 10, 20, 30, self::SOURCE, 'entity:project',
                'foundation_type', 'strip', null, 1.0, 'user_assumption', 'confirmed', [], 2,
                'fact:foundation',
            )], []);
        };

        try {
            $coordinator->refresh(10, 20, 30, $planning);
            self::fail('Stale completeness replay was accepted.');
        } catch (InvalidArgumentException) {
            self::assertNull($repository->currentCompleteness(10, 20, 30));
            self::assertSame(1, $repository->completenessWriteCount);
        }
    }

    public function test_production_di_caller_persistence_and_forward_only_migration_are_present(): void
    {
        $root = dirname(__DIR__, 4);
        $caller = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Planning/ProjectPlanningPipeline.php');
        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        $migration = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_11_000710_create_completeness_planning_projections.php');

        self::assertIsString($caller);
        self::assertStringContainsString('$this->completeness->refresh(', $caller);
        self::assertIsString($provider);
        self::assertStringContainsString(ProjectCompletenessCoordinator::class, $provider);
        self::assertIsString($migration);
        self::assertStringContainsString('implements ForwardOnlyMigration', $migration);
        self::assertStringContainsString('estimate_generation_completeness_findings', $migration);
        self::assertStringContainsString('estimate_generation_technology_work_packages', $migration);
        self::assertStringNotContainsString('Schema::drop', $migration);
    }

    private function repository(): InMemoryProjectModelRepository
    {
        $repository = new InMemoryProjectModelRepository;
        $evidence = new Evidence('evidence:1', 10, 20, 30, self::SOURCE, 'artifact:project', 'drawing', page: 1);
        $repository->saveSourceModel([], [
            $this->fact('fact:foundation', 'foundation_type', 'slab'),
            $this->fact('fact:roof', 'roof_type', 'pitched'),
        ], [$evidence]);

        return $repository;
    }

    private function planning(InMemoryProjectModelRepository $repository): ProjectPlanningResult
    {
        $capture = $repository->snapshotForPlanning(10, 20, 30, 100);

        return new ProjectPlanningResult(self::SOURCE, $capture['token'], 'catalog-v1', hash('sha256', 'catalog-v1'), [], []);
    }

    private function coordinator(InMemoryProjectModelRepository $repository, array $data): ProjectCompletenessCoordinator
    {
        $catalog = CompletenessRuleCatalog::fromArray($data);

        return new ProjectCompletenessCoordinator(
            $repository,
            new ProjectCompletenessAnalyzer($catalog, new TechnologyWorkPackageBuilder(static fn (string $key): string => 'Человекочитаемое название'), 50, 50, 200),
            $catalog,
            maxFacts: 100,
        );
    }

    private function fact(string $id, string $type, mixed $value): Fact
    {
        return new Fact($id, 10, 20, 30, self::SOURCE, 'entity:project', $type, $value, null, 1.0, 'document', 'confirmed', ['evidence:1']);
    }
}
