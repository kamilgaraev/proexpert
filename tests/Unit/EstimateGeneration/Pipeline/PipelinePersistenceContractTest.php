<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelinePersistenceContractTest extends TestCase
{
    #[Test]
    public function checkpoint_schema_bounds_and_atomically_persists_stage_output(): void
    {
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php');
        $store = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentPipelineCheckpointStore.php');

        self::assertIsString($migration);
        self::assertStringContainsString("jsonb('output_payload')->nullable()", $migration);
        self::assertStringContainsString('pg_column_size(output_payload) <= 4096', $migration);
        self::assertStringContainsString('output_payload IS NOT NULL', $migration);
        self::assertIsString($store);
        self::assertStringContainsString('$this->database->transaction(', $store);
        self::assertStringContainsString('$this->completionHook->beforeComplete(', $store);
        self::assertStringContainsString("'output_payload' => json_encode(", $store);
        self::assertStringNotContainsString('PipelineArtifactStore', $store);
        $publisher = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/PublishValidatedDraft.php');
        self::assertIsString($publisher);
        self::assertStringContainsString('$result->transientData', $publisher);
        self::assertStringContainsString('$claim->context->baseInputVersion', $publisher);
        self::assertStringContainsString('$this->packages->syncFromDraft($session, $draft, $claim->context->baseInputVersion)', $publisher);
        self::assertStringNotContainsString('$claim->context->inputVersion, $draft[\'source_input_version\']', $publisher);
        self::assertStringNotContainsString('->read(', $publisher);
    }

    #[Test]
    public function storage_reset_invalidates_persisted_outputs_before_removing_the_legacy_locator(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 4).'/database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString("output_payload = output_payload - 'artifact'", $migration);
        self::assertStringNotContainsString("output_payload #- '{artifact,version_id}'", $migration);
        self::assertStringContainsString("status = 'completed'", $migration);
        self::assertStringContainsString("'invalidated'", $migration);
        self::assertStringContainsString('eg_checkpoint_immutable_update', $migration);
    }
}
