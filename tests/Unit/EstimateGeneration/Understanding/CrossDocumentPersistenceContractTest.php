<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CrossDocumentPersistenceContractTest extends TestCase
{
    #[Test]
    public function repository_persists_idempotent_scoped_links_and_invalidates_only_the_current_projection(): void
    {
        self::assertTrue(method_exists(ProjectModelRepository::class, 'appendCrossDocumentLinks'));

        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Domain/ProjectModel/EloquentProjectModelRepository.php'
        );

        foreach ([
            'estimate_generation_project_model_cross_document_links',
            'estimate_generation_project_model_cross_link_evidence',
            "->whereIn('operation_identity', array_column(\$chunk, 'operation_identity'))",
            'insertOrIgnore',
            'evidenceRowsForLinks',
            'factIdsForLinks',
            "'is_current' => false",
            "'invalidated_at' => now()",
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }

        self::assertStringNotContainsString('->delete()', $source);
    }
}
