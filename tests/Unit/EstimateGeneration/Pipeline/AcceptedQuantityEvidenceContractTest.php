<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedQuantityEvidenceContractTest extends TestCase
{
    #[Test]
    public function planning_stage_is_pure_and_accepted_materialization_is_checkpoint_bound(): void
    {
        $root = dirname(__DIR__, 4);
        $stage = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/PlanWorkItemsStage.php');
        $materializer = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/AcceptedQuantityEvidenceMaterializer.php');
        $store = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentPipelineCheckpointStore.php');
        $persistence = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php');

        self::assertStringNotContainsString('EvidenceRepository', $stage);
        self::assertStringNotContainsString('insertOrGet', $stage);
        self::assertStringContainsString("'quantity_evidence_descriptor'", $stage);
        self::assertStringContainsString("'checkpoint_id' => \$claim->checkpointId", $materializer);
        self::assertStringContainsString("'output_version' => \$result->outputVersion", $materializer);
        self::assertStringContainsString('$this->completionHook->beforeComplete', $store);
        self::assertStringContainsString("->where('checkpoint.status', 'completed')", $persistence);
        self::assertStringContainsString("->whereColumn('checkpoint.output_version', 'accepted.output_version')", $persistence);
    }

    #[Test]
    public function follow_up_migration_closes_provenance_and_norm_resource_insert(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001500_publish_accepted_evidence_and_close_pricing_provenance.php');

        foreach (['construction_resource_id', 'resource_name', 'resource_type', 'raw_payload_hash',
            'price_raw_payload_hash', 'machine_salary_price', 'machine_price_without_salary',
            'norm_dataset', 'price_dataset', 'regional_version', 'conversion'] as $field) {
            self::assertStringContainsString("'{$field}'", $migration);
        }
        self::assertStringContainsString('BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_norm_resources', $migration);
        self::assertStringContainsString('evidence_decimal_must_be_string', $migration);
    }
}
