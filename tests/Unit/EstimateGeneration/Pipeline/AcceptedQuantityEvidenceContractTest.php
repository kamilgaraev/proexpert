<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
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

    #[Test]
    public function accepted_evidence_mapping_is_database_validated_and_immutable(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001600_harden_accepted_evidence_mapping.php');

        self::assertStringContainsString('eg_checkpoint_acceptance_identity_uq', $migration);
        self::assertStringContainsString('eg_evidence_acceptance_identity_uq', $migration);
        self::assertStringContainsString('eg_accepted_evidence_checkpoint_fk', $migration);
        self::assertStringContainsString('eg_accepted_evidence_node_fk', $migration);
        self::assertStringContainsString("checkpoint.stage IS DISTINCT FROM 'plan_work_items'", $migration);
        self::assertStringContainsString("checkpoint.status IS DISTINCT FROM 'completed'", $migration);
        self::assertStringContainsString('checkpoint.output_version IS DISTINCT FROM NEW.output_version', $migration);
        self::assertStringContainsString("evidence.type IS DISTINCT FROM 'work_item'", $migration);
        self::assertStringContainsString('evidence.invalidated_at IS NOT NULL', $migration);
        self::assertStringContainsString("evidence.locator->>'item_key' !~ '^item:[a-f0-9]{64}$'", $migration);
        self::assertStringContainsString("TG_OP<>'INSERT'", $migration);
        self::assertStringContainsString('REVOKE ALL ON FUNCTION public.eg_accepted_evidence_validate() FROM PUBLIC', $migration);
        self::assertStringContainsString("\$table->boolean('is_active')->default(true)", $migration);
        self::assertStringContainsString('estimate_generation.unit_conversion_in_use', $migration);
        self::assertStringContainsString("jsonb_build_object('is_active'", $migration);
        self::assertStringContainsString('COALESCE(logical_key, key), revision DESC, id DESC', $migration);
        self::assertStringContainsString('eg_package_item_latest_revision_idx', $migration);
    }

    #[Test]
    public function accepted_evidence_lookup_uses_checkpoint_and_evidence_scopes(): void
    {
        $persistence = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php');

        self::assertStringContainsString("->join('estimate_generation_evidence as evidence'", $persistence);
        self::assertStringContainsString("->where('checkpoint.organization_id', \$session->organization_id)", $persistence);
        self::assertStringContainsString("->where('evidence.organization_id', \$session->organization_id)", $persistence);
        self::assertStringNotContainsString("->where('accepted.organization_id', \$session->organization_id)", $persistence);
    }

    #[Test]
    public function migration_001500_down_restores_001400_pricing_contract(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001500_publish_accepted_evidence_and_close_pricing_provenance.php');

        self::assertStringContainsString('DROP FUNCTION IF EXISTS public.eg_expected_package_item_price_closed(bigint)', $migration);
        self::assertStringContainsString('expected:=public.eg_expected_package_item_price(p_item_id);', $migration);
        self::assertStringContainsString('expected:=public.eg_expected_package_item_price(NEW.id);', $migration);
        self::assertStringContainsString('GRANT EXECUTE ON FUNCTION public.eg_finalize_package_item_price(bigint) TO CURRENT_USER', $migration);
    }

    #[Test]
    public function quantity_descriptor_preserves_high_precision_decimal_and_rejects_float(): void
    {
        $stage = (new \ReflectionClass(PlanWorkItemsStage::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($stage, 'attachQuantityEvidence');
        $context = new PipelineContext(1, 2, 3, 0, 'input:v1', 'processing');
        $value = '123456789.123456789123456789';

        $exact = $method->invoke($stage, $context, ['key' => 'exact', 'quantity' => $value, 'unit' => 'm2']);
        $float = $method->invoke($stage, $context, ['key' => 'float', 'quantity' => 1.25, 'unit' => 'm2']);

        self::assertSame($value, $exact['quantity_evidence_descriptor']['quantity']);
        self::assertSame($value, $exact['quantity']);
        self::assertArrayNotHasKey('quantity_evidence_descriptor', $float);
    }
}
