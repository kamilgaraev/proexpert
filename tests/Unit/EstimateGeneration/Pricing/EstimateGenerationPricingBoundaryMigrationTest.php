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

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001200_harden_estimate_generation_pricing_boundary.php');
    }
}
