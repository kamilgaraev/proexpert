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

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001200_harden_estimate_generation_pricing_boundary.php');
    }
}
