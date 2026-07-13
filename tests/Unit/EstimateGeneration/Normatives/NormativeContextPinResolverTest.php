<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeContextPinResolverTest extends TestCase
{
    #[Test]
    public function exact_catalog_and_regional_price_identity_is_resolved_by_production_source_contract(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public int $calls = 0;

            public function resolve(NormativeContextPinData $requested): ?NormativeContextPinData
            {
                $this->calls++;

                return $requested->datasetVersion === 'fsnb-2026.1' && $requested->priceVersion === 'prices-2026.07'
                    ? new NormativeContextPinData(
                        $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
                        $requested->regionId, $requested->priceZoneId, $requested->periodId,
                        $requested->regionalPriceVersionId, $requested->priceVersion,
                        [['candidate_id' => '101']], str_repeat('a', 64),
                    )
                    : null;
            }
        };
        $resolver = new NormativeContextPinResolver($source);
        $context = [
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'year' => 2026, 'quarter' => 3,
        ];

        $pin = $resolver->resolve($context);

        self::assertSame('pinned', $pin['status']);
        self::assertSame(77, $pin['dataset_id']);
        self::assertSame(11, $pin['regional_price_version_id']);
        self::assertSame([['candidate_id' => '101']], $pin['catalog_candidates']);
        self::assertSame('2026-07-01', $pin['applicability_date']);
        self::assertSame($pin, $resolver->resolve($context));
        self::assertSame(2, $source->calls);
    }

    #[Test]
    public function incomplete_or_inconsistent_resource_context_fails_closed(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public function resolve(NormativeContextPinData $requested): ?NormativeContextPinData
            {
                return null;
            }
        };
        $resolver = new NormativeContextPinResolver($source);

        self::assertSame('normative_resource_context_not_pinned', $resolver->resolve([
            'normative_dataset_version' => 'fsnb-2026.1', 'business_date' => '2026-07-01',
        ])['blocking_issues'][0]);
        self::assertSame('normative_resource_context_not_approved', $resolver->resolve([
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'wrong',
            'business_date' => '2026-07-01',
        ])['blocking_issues'][0]);
    }

    #[Test]
    public function production_source_has_no_latest_first_or_cross_dataset_fallback(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php');

        self::assertStringNotContainsString('latest(', $source);
        self::assertStringNotContainsString('first(', $source);
        self::assertStringNotContainsString('orderByDesc(', $source);
        self::assertStringContainsString("->where('id', \$requested->datasetId)", $source);
        self::assertStringContainsString("->where('prices.regional_price_version_id', \$requested->regionalPriceVersionId)", $source);
        self::assertStringContainsString("->where('source_type', 'fsnb_2022')", $source);
    }
}
