<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationPipelineCheckpointContractTest extends TestCase
{
    #[Test]
    public function migration_defines_postgresql_checkpoint_invariants(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/'
            .'BusinessModules/Addons/EstimateGeneration/migrations/'
            .'2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('attempt_count >= 1', $source);
        self::assertStringContainsString("jsonb_typeof(metrics) = 'object'", $source);
        self::assertStringContainsString("jsonb_typeof(warnings) = 'array'", $source);
        foreach ([
            'understand_documents', 'understand_object', 'extract_quantities',
            'plan_work_items', 'match_normatives', 'assemble_resources',
            'resolve_prices', 'build_draft', 'validate_draft',
        ] as $stage) {
            self::assertStringContainsString("'{$stage}'", $source);
        }
        self::assertStringContainsString('last_error_fingerprint', $source);
    }

    #[Test]
    public function eloquent_store_uses_injected_connection_for_every_query(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2)
            .'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentPipelineCheckpointStore.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('private Connection $database', $source);
        self::assertStringContainsString('->setConnection($this->database->getName())', $source);
        self::assertStringNotContainsString('Facades\\DB', $source);
    }
}
