<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\TestCase;

final class PipelinePostgresSchemaContractTest extends TestCase
{
    public function test_checkpoint_migration_enforces_production_invariants(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/';
        $checkpoint = file_get_contents($root.'2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php');

        self::assertIsString($checkpoint);
        self::assertStringContainsString('eg_checkpoint_immutable_guard', $checkpoint);
        self::assertStringContainsString('eg_checkpoint_delete_guard', $checkpoint);
        self::assertStringContainsString('eg_checkpoint_aggregate_guard', $checkpoint);
        self::assertStringContainsString('FOR UPDATE', $checkpoint);
        self::assertStringContainsString('8388608', $checkpoint);
        self::assertStringContainsString('dependency_versions', $checkpoint);
    }
}
