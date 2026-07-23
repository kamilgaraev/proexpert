<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublishedRegionalPriceLifecycleMigrationTest extends TestCase
{
    #[Test]
    public function published_catalog_rows_stay_immutable_across_lifecycle_statuses(): void
    {
        $source = $this->source();

        self::assertStringContainsString(
            "status IN ('active','superseded','rolled_back')",
            $source,
        );
        self::assertStringContainsString(
            'BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_resource_prices',
            $source,
        );
        self::assertStringContainsString(
            'estimate_generation.published_pricing_catalog_is_immutable',
            $source,
        );
        self::assertStringContainsString("TG_OP IN ('UPDATE','DELETE') THEN OLD.regional_price_version_id", $source);
        self::assertStringContainsString("TG_OP IN ('UPDATE','INSERT') THEN NEW.regional_price_version_id", $source);
    }

    #[Test]
    public function only_explicit_published_version_lifecycle_transitions_are_allowed(): void
    {
        $source = $this->source();

        foreach ([
            "OLD.status='active' AND NEW.status='superseded'",
            "OLD.status='active' AND NEW.status='rolled_back'",
            "OLD.status IN ('superseded','rolled_back') AND NEW.status='active'",
            "to_jsonb(NEW) - ARRAY['status','activated_at','superseded_at','rolled_back_at','updated_at']",
            "TG_TABLE_NAME='estimate_regional_price_versions' AND TG_OP='UPDATE'",
            'public.eg_regional_price_lifecycle_transition_allowed(OLD,NEW)',
            'estimate_generation.published_regional_price_version_is_immutable',
            'estimate_generation.regional_price_lifecycle_transition_invalid',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }

    #[Test]
    public function pinned_historical_versions_remain_valid_without_mutable_lifecycle_provenance(): void
    {
        $source = $this->source();

        self::assertStringContainsString("rv.status IN ('active','superseded','rolled_back')", $source);
        self::assertStringContainsString('removeLifecycleFieldsFromProvenance', $source);
        self::assertStringContainsString("pg_get_functiondef('public.eg_pricing_provenance(bigint)'::regprocedure)", $source);
        self::assertStringContainsString("'version_key',rv.version_key,'status',rv.status", $source);
        self::assertStringContainsString("'version_key',rv.version_key,'files_count',rv.files_count", $source);
    }

    private function source(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_20_000500_allow_published_regional_price_lifecycle_transitions.php',
        );

        self::assertIsString($source);

        return $source;
    }
}
