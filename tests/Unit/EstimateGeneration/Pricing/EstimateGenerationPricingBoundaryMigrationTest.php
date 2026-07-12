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

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001200_harden_estimate_generation_pricing_boundary.php');
    }
}
