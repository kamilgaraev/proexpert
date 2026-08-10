<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class ProjectModelRepositoryBoundaryTest extends TestCase
{
    #[Test]
    public function repository_has_one_atomic_write_and_versioned_read_boundary(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('room:1', 1, 2, 3, $source, 'room', 'room:1', ['name' => 'room']);
        $fact = new Fact('fact:1', 1, 2, 3, $source, 'room:1', 'area', '7.94', 'm2', 1, 'document', 'confirmed', ['evidence:1']);

        $this->expectException(InvalidArgumentException::class);
        $repository->saveSourceModel([$entity], [$fact], []);
    }

    #[Test]
    public function repository_interface_does_not_expose_fragmented_entity_fact_or_decision_writers(): void
    {
        self::assertFalse(method_exists(ProjectModelRepository::class, 'appendEntities'));
        self::assertFalse(method_exists(ProjectModelRepository::class, 'appendFacts'));
        self::assertFalse(method_exists(ProjectModelRepository::class, 'appendDecisions'));
    }
}
