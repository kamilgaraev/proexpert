<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeIntentCandidateRanker;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeResourceRowData;
use InvalidArgumentException;
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

            public array $intents = [];

            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                $this->calls++;
                $this->intents = $intents;

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

        $intents = [['search_text' => 'РњРѕРЅС‚Р°Р¶ РєРёСЂРїРёС‡РЅС‹С… СЃС‚РµРЅ', 'unit' => 'm2', 'code' => null]];
        $pin = $resolver->resolve($context, $intents);

        self::assertSame('pinned', $pin['status']);
        self::assertSame(77, $pin['dataset_id']);
        self::assertSame(11, $pin['regional_price_version_id']);
        self::assertSame([['candidate_id' => '101']], $pin['catalog_candidates']);
        self::assertSame('2026-07-01', $pin['applicability_date']);
        self::assertSame($pin, $resolver->resolve($context, $intents));
        self::assertSame(2, $source->calls);
        self::assertSame($intents, $source->intents);
    }

    #[Test]
    public function incomplete_or_inconsistent_resource_context_fails_closed(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
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
        ], [['search_text' => 'РњРѕРЅС‚Р°Р¶ СЃС‚РµРЅС‹', 'unit' => 'm2']])['blocking_issues'][0]);
        self::assertSame('normative_work_intents_not_pinned', $resolver->resolve([
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'business_date' => '2026-07-01',
        ], [])['blocking_issues'][0]);
    }

    #[Test]
    public function unique_intent_limit_is_checked_before_source_query_while_exact_limit_is_bounded(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public int $calls = 0;

            public int $received = 0;

            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                $this->calls++;
                $this->received = count($intents);

                return new NormativeContextPinData(
                    $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
                    $requested->regionId, $requested->priceZoneId, $requested->periodId,
                    $requested->regionalPriceVersionId, $requested->priceVersion,
                    [['candidate_id' => '101']], str_repeat('a', 64),
                );
            }
        };
        $resolver = new NormativeContextPinResolver($source);
        $context = [
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'business_date' => '2026-07-01',
        ];
        $intents = array_map(
            static fn (int $index): array => ['search_text' => 'intent-'.$index, 'unit' => 'm2'],
            range(1, 65),
        );

        self::assertSame('normative_work_intents_limit_exceeded', $resolver->resolve($context, $intents)['blocking_issues'][0]);
        self::assertSame(0, $source->calls);
        self::assertSame('pinned', $resolver->resolve($context, array_slice($intents, 0, 64))['status']);
        self::assertSame(1, $source->calls);
        self::assertSame(64, $source->received);
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
        self::assertStringContainsString("'resources.id as norm_resource_id'", $source);
        self::assertStringContainsString('resolveForIntents', $source);
        self::assertStringNotContainsString("->orderBy('norms.id')->limit(129)", $source);
    }

    #[Test]
    public function exact_relevant_norm_above_first_128_is_selected_before_unrelated_candidates_are_bounded(): void
    {
        $candidates = [];
        for ($id = 1; $id <= 200; $id++) {
            $candidates[] = (object) [
                'id' => $id,
                'code' => '10-01-'.$id,
                'name' => $id === 199 ? 'РњРѕРЅС‚Р°Р¶ РЅРµСЃСѓС‰РµР№ СЃС‚РµРЅС‹' : 'РњРѕРЅС‚Р°Р¶ СЃС‚РµРЅС‹ РІР°СЂРёР°РЅС‚ '.$id,
                'canonical_unit' => 'm2',
                'unit' => 'm2',
            ];
        }
        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'РњРѕРЅС‚Р°Р¶ РЅРµСЃСѓС‰РµР№ СЃС‚РµРЅС‹', 'unit' => 'm2', 'code' => null,
        ]]);

        self::assertNotNull($selected);
        self::assertContains(199, array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
        self::assertLessThanOrEqual(16, count($selected));
        self::assertNull((new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'roof waterproofing', 'unit' => 'm2', 'code' => null,
        ]]));
    }

    #[Test]
    public function exact_database_resource_row_preserves_positive_identities_and_rejects_relation_mismatch(): void
    {
        $row = (object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => 501, 'price_construction_resource_id' => 501,
            'price_id' => 9001, 'resource_type' => 'material', 'resource_code' => '01.7.01',
            'resource_name' => 'Кирпич', 'unit' => 'pcs', 'quantity' => 50,
        ];
        $mapped = NormativeResourceRowData::fromDatabaseRow($row);

        self::assertSame(101, $mapped->estimateNormId);
        self::assertSame('materials', $mapped->group);
        self::assertSame(7001, $mapped->resource['norm_resource_id']);
        self::assertSame(9001, $mapped->resource['price_id']);
        self::assertSame(501, $mapped->resource['linked_resource_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('normative_resource_price_relation_invalid');
        NormativeResourceRowData::fromDatabaseRow((object) [...(array) $row, 'price_construction_resource_id' => 502]);
    }
}
