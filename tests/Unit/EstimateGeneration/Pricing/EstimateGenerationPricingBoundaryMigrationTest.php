<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationPricingBoundaryMigrationTest extends TestCase
{
    #[Test]
    public function database_owns_quantity_resource_price_conversion_and_money_validation(): void
    {
        $source = $this->source();

        foreach (['quantity_evidence_fingerprint', 'estimate_norm_resources', 'estimate_resource_prices',
            'estimate_generation_unit_conversions', 'regional_price_version_id', 'base_price IS NULL OR rp.base_price<=0',
            'norm_resource_set_mismatch', 'quantity_evidence_mismatch', 'database_built_price_mismatch',
            'eg_finalize_package_item_price', "'work_cost','0.00'", 'sha256(convert_to(canonical'] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }

    #[Test]
    public function priced_items_inputs_and_catalog_rows_are_append_only(): void
    {
        $source = $this->source();

        self::assertStringContainsString("string('logical_key', 180)", $source);
        self::assertStringContainsString("unsignedInteger('revision')", $source);
        self::assertStringContainsString("foreignId('supersedes_item_id')", $source);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE ON estimate_generation_package_items', $source);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE ON estimate_resource_prices', $source);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE ON estimate_generation_package_item_price_inputs', $source);
    }

    #[Test]
    public function ordinary_estimate_module_is_outside_the_follow_up(): void
    {
        self::assertStringNotContainsString("Schema::table('estimates'", $this->source());
        self::assertStringNotContainsString('BusinessModules/Features/BudgetEstimates', $this->source());
    }

    #[Test]
    public function final_hardening_closes_every_write_path_and_secures_database_functions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001400_finalize_estimate_generation_pricing_boundary.php');

        foreach ([
            'pricing_finalized_at',
            'BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_generation_package_item_price_inputs',
            'estimate_generation.price_input_set_closed',
            'estimate_norm_resources',
            'estimate_regional_price_versions',
            'estimate_dataset_versions',
            'SECURITY DEFINER',
            'SET search_path = pg_catalog, public',
            'REVOKE ALL ON FUNCTION public.eg_finalize_package_item_price(bigint) FROM PUBLIC',
            'CREATE CONSTRAINT TRIGGER eg_package_item_price_input_validate',
            'lockForUpdate()',
        ] as $required) {
            self::assertStringContainsString($required, $required === 'lockForUpdate()'
                ? (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php')
                : $source);
        }
    }

    #[Test]
    public function follow_up_down_restores_legacy_package_key_uniqueness(): void
    {
        self::assertStringContainsString(
            "unique(['package_id', 'key'], 'estimate_generation_package_items_package_id_key_unique')",
            $this->source(),
        );
    }

    #[Test]
    public function zero_quantity_normative_rows_do_not_require_price_inputs(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000100_price_only_positive_normative_resources.php');

        self::assertStringContainsString('quantity>0', $source);
        self::assertStringContainsString('expected_price_resource_count_contract_changed', $source);
        self::assertStringContainsString("pg_get_functiondef('public.eg_expected_package_item_price(bigint)'::regprocedure)", $source);
        self::assertStringContainsString('JOIN public.estimate_norm_resources counted_resources', $source);
        self::assertStringContainsString('counted_resources.quantity > 0', $source);

        $compatibility = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000200_keep_zero_resource_price_inputs_compatible.php');
        self::assertStringContainsString('counted_resources.quantity > 0', $compatibility);
        self::assertStringContainsString('preg_match($positiveCountPattern, $definition)', $compatibility);
        self::assertStringNotContainsString("replaceExpectedResourceCount('')", $source);
    }

    #[Test]
    public function summary_rows_are_not_resource_price_inputs(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000300_exclude_normative_summary_rows_from_pricing.php');

        self::assertStringContainsString("resource_type <> 'summary'", $source);
        self::assertStringContainsString('eg_expected_package_item_price', $source);
        self::assertStringContainsString('eg_pricing_provenance', $source);
    }

    #[Test]
    public function parsed_fsbc_prices_are_validated_when_regional_resource_price_is_absent(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000400_allow_pinned_fsbc_resource_prices.php');

        self::assertStringContainsString("source_type NOT IN ('fsbc','fsnb_2022')", $source);
        self::assertStringContainsString("status <> 'parsed'", $source);
        self::assertStringContainsString('LEFT JOIN public.estimate_regional_price_versions rv', $source);
    }

    #[Test]
    public function database_price_scales_work_quantity_to_the_norm_measurement_unit(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000600_scale_quantity_by_norm_unit.php');

        self::assertIsString($migration);
        self::assertStringContainsString('eg_norm_quantity_factor', $migration);
        self::assertStringContainsString("p_work_unit = 'm2'", $migration);
        self::assertStringContainsString("p_work_unit = 'pcs'", $migration);
        self::assertStringContainsString("p_work_unit = 'kg'", $migration);
        self::assertStringContainsString("chr(178),'2'", $migration);
        self::assertStringContainsString("U&'\\0448\\0442'", $migration);
        self::assertStringContainsString('eg_expected_package_item_price_v2', $migration);
        self::assertStringContainsString("'pricing_formula_version','norm_measurement:v2'", $migration);
        self::assertStringContainsString("'norm_measurement_unit',norm_unit", $migration);
        self::assertStringContainsString("'work_to_norm_factor',norm_quantity_factor::text", $migration);
        self::assertStringContainsString("public.eg_norm_quantity_factor(evidence.value->>'unit', norm_unit)", $migration);
        self::assertStringContainsString('estimate_generation.norm_quantity_unit_mismatch', $migration);
        self::assertStringContainsString('estimate_generation.norm_quantity_formula_rollback_blocked', $migration);
        self::assertStringContainsString("definition('public.eg_expected_package_item_price(bigint)')", $migration);
    }

    #[Test]
    public function pricing_canonicalization_parenthesizes_json_extraction_before_text_concatenation(): void
    {
        $base = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/';
        $pricing = file_get_contents($base.'2026_07_18_000600_scale_quantity_by_norm_unit.php');
        $repair = file_get_contents($base.'2026_07_18_000700_parenthesize_pricing_evidence_unit.php');

        self::assertIsString($pricing);
        self::assertIsString($repair);
        self::assertStringContainsString("||(evidence.value->>'unit')||", $pricing);
        self::assertStringContainsString("||(evidence.value->>'unit')||", $repair);
        self::assertStringContainsString("definition('public.eg_expected_package_item_price_v2(bigint)')", $repair);
        self::assertStringContainsString('estimate_generation.pricing_evidence_unit_precedence_contract_changed', $repair);
        self::assertStringContainsString('hasParenthesizedEvidenceUnit', $repair);
        self::assertStringNotContainsString('pricing_evidence_unit_precedence_rollback_contract_changed', $repair);
    }

    #[Test]
    public function project_selected_resource_price_keeps_the_abstract_norm_resource_in_the_database_formula(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000800_price_project_selected_resources.php');

        self::assertIsString($migration);
        self::assertStringContainsString('eg_expected_package_item_price_v3', $migration);
        self::assertStringContainsString("\"'norm_measurement:v2'\"", $migration);
        self::assertStringContainsString("\"'project_resource:v3'\"", $migration);
        self::assertStringContainsString("LOWER(COALESCE(nr.raw_payload->>'source_tag', '')) = 'abstractresource'", $migration);
        self::assertStringContainsString("nr.resource_code !~ '^[0-9]{2}\\\\.[0-9]\\\\.[0-9]{2}\\\\.[0-9]{2}$'", $migration);
        self::assertStringContainsString("replace(nr.resource_code, '.', '\\\\.')", $migration);
        self::assertStringContainsString("'-[0-9]{4}$'", $migration);
        self::assertStringContainsString('public.eg_expected_package_item_price_closed_v3(p_item_id)', $migration);
        self::assertStringContainsString("='project_resource:v3'", $migration);
        self::assertStringContainsString('estimate_generation.project_resource_formula_rollback_blocked', $migration);
        self::assertStringContainsString('REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v3(bigint) FROM PUBLIC', $migration);
    }

    #[Test]
    public function supplementary_project_material_is_database_priced_from_an_immutable_versioned_rule(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_20_000100_finalize_supplementary_project_material_prices.php');

        self::assertIsString($migration);
        foreach ([
            "Schema::create('estimate_generation_project_material_rules'",
            "Schema::create('estimate_generation_package_item_project_price_inputs'",
            'eg_expected_package_item_price_v4',
            'eg_expected_project_material_price_id_v4',
            'project_material_candidate_pool:v2',
            'candidate_resource_price_ids',
            'supplementary_project_material:v4',
            'quantity_per_work_unit',
            'project_material_price_input_mismatch',
            "dv.source_type IN ('fsbc','fsnb_2022')",
            "selection_policy'='semantic_group_median'",
            "selection_policy'='semantic_catalog_attributes_median'",
            'row_number() OVER (ORDER BY base_price,resource_code,dataset_source_priority,id DESC)',
            "selection_policy='exact_code' AND exact_rank=1",
            'source_priority=(SELECT min(source_priority) FROM eligible)',
            'project_material_evidence',
            'eg_project_material_rule_immutable',
            'eg_project_price_input_append',
            'eg_finalized_project_material_price_immutable',
            'eg_finalized_project_material_dataset_immutable',
            'project_material_formula_rollback_blocked',
            'REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v4(bigint) FROM PUBLIC',
        ] as $required) {
            self::assertStringContainsString($required, $migration);
        }
    }

    #[Test]
    public function supplementary_project_material_price_fields_are_canonicalized_at_the_database_boundary(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_20_000200_canonicalize_supplementary_project_material_price_fields.php');

        self::assertIsString($migration);
        foreach ([
            'trim(rp.unit)=trim(x.source_unit)',
            'trim(rp.resource_code)=trim(x.preferred_resource_code)',
            'trim(rp.resource_code)=trim(r.preferred_resource_code)',
            'lower(trim(rp.resource_name))',
            'trim(dv.source_type)',
            'trim(rv.version_key)',
            'trim(dv.version_key)',
            "'price_unit',trim(rp.unit)",
            'project_material_canonicalization_rollback_blocked',
            'SECURITY DEFINER',
            'REVOKE ALL ON FUNCTION public.eg_expected_project_material_price_id_v4(bigint) FROM PUBLIC',
            'REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v4(bigint) FROM PUBLIC',
            'REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v4(bigint) FROM PUBLIC',
        ] as $required) {
            self::assertStringContainsString($required, $migration);
        }
    }

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001200_harden_estimate_generation_pricing_boundary.php');
    }
}
