<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedQuantityEvidenceContractTest extends TestCase
{
    #[Test]
    public function planning_consumes_only_canonical_quantity_output(): void
    {
        $root = dirname(__DIR__, 4);
        $stage = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/PlanWorkItemsStage.php');

        self::assertStringContainsString('AcceptedQuantityEvidenceMaterializer', $stage);
        self::assertStringNotContainsString('quantity_evidence_descriptor', $stage);
        self::assertStringContainsString("\$quantityOutput['building_quantities']['quantities']", $stage);
        self::assertStringContainsString('WorkItemQuantityMapper', $stage);
        self::assertStringContainsString('->map($quantityKey, $quantities)', $stage);
        self::assertStringContainsString("'quantity_evidence'", $stage);
        self::assertStringContainsString("'quantity_mapping_missing'", $stage);
        self::assertFileExists($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/AcceptedQuantityEvidenceMaterializer.php');
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
        self::assertStringContainsString('pricing_provenance_payload_too_large', $migration);
        self::assertStringContainsString('octet_length(COALESCE(payload', $migration);
        foreach (['norm_collection', 'work_composition_hash', 'meta_hash', 'metadata_hash',
            'eg_used_norm_immutable', 'eg_used_norm_collection_immutable', 'eg_used_dataset_version_immutable',
            'eg_used_resource_price_immutable', 'eg_used_regional_version_immutable'] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
    }

    #[Test]
    public function pricing_uses_direct_canonical_evidence_identity_without_checkpoint_descriptor_lookup(): void
    {
        $persistence = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php');

        self::assertStringContainsString('AuthoritativePackagePricingGuard', $persistence);
        self::assertStringContainsString('pricingGuard->inputs(', $persistence);
        self::assertStringNotContainsString('estimate_generation_accepted_evidence', $persistence);
        self::assertStringNotContainsString('quantity_evidence_descriptor', $persistence);
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
    public function canonical_quantity_mapping_preserves_formula_source_and_evidence(): void
    {
        $stage = (new \ReflectionClass(PlanWorkItemsStage::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($stage, 'attachCanonicalQuantity');
        $quantity = [
            'key' => 'finish.floor', 'unit' => 'm2', 'amount' => '123456789.123456789123456789',
            'formula_key' => 'floor.net_area', 'formula_version' => 'v2',
            'formula_inputs' => ['gross' => '123456790'], 'source' => 'evidenced',
            'evidence_ids' => ['evidence:page:1'], 'model_version' => 'building-model:v1',
            'assumptions' => [], 'review_blockers' => [],
        ];

        $mapped = $method->invoke($stage, ['key' => 'floor', 'quantity' => '1', 'unit' => 'pcs', 'metadata' => ['quantity_key' => 'finish.floor']], ['finish.floor' => $quantity]);
        $missing = $method->invoke($stage, ['key' => 'wall', 'quantity' => '7', 'unit' => 'm2', 'metadata' => ['quantity_key' => 'finish.wall']], ['finish.floor' => $quantity]);

        self::assertSame($quantity['amount'], $mapped['quantity']);
        self::assertSame($quantity, $mapped['quantity_evidence']);
        self::assertSame('quantity_mapping_missing', $missing['pricing_blocker']);
        self::assertArrayNotHasKey('quantity_evidence', $missing);
    }
}
