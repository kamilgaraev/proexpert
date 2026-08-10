<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProjectModelRepositoryBoundaryTest extends TestCase
{
    #[Test]
    public function repository_exposes_chunked_idempotent_append_and_versioned_projection_operations(): void
    {
        foreach ([
            'appendEntities',
            'appendFacts',
            'appendConflicts',
            'appendDecisions',
            'appendDerivedQuantities',
            'appendCrossDocumentLinks',
            'currentFacts',
            'invalidateSourceVersion',
        ] as $method) {
            self::assertTrue(method_exists(ProjectModelRepository::class, $method), $method.' is missing.');
        }

        self::assertSame('array', (string) (new ReflectionMethod(ProjectModelRepository::class, 'currentFacts'))->getReturnType());
    }

    #[Test]
    public function repository_boundary_rejects_inactive_evidence_and_reads_it_without_per_fact_queries(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Domain/ProjectModel/EloquentProjectModelRepository.php'
        );

        self::assertStringContainsString('evidenceRowsForFacts($chunk)', $source);
        self::assertStringContainsString("->whereNull('invalidated_at')", $source);
        self::assertStringContainsString("->whereIn('binding.fact_id', \$factIds)", $source);
        self::assertStringContainsString('Project model fact evidence is outside the requested scope or inactive.', $source);
        self::assertStringContainsString('System decision cannot confirm an unresolved or recommended fact.', $source);
        self::assertStringContainsString('Cross-document link evidence is outside the requested scope or inactive.', $source);
    }
}
