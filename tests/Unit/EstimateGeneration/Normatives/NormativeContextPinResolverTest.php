<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeIntentCandidateRanker;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeResourceRowData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PinnedNormativeCandidateFactory;
use DateTimeImmutable;
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
    public function production_source_keeps_norm_dataset_exact_and_combines_authoritative_base_prices(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php');

        self::assertStringNotContainsString('latest(', $source);
        self::assertStringNotContainsString('first(', $source);
        self::assertStringNotContainsString("->orderByDesc('norms.id')", $source);
        self::assertStringContainsString("->where('id', \$requested->datasetId)", $source);
        self::assertStringContainsString("->where('prices.regional_price_version_id', \$requested->regionalPriceVersionId)", $source);
        self::assertStringContainsString("->where('status', 'active')", $source);
        self::assertStringContainsString('->whereExists(function ($priced) use ($requested, $basePriceDatasetIds)', $source);
        self::assertStringContainsString("->where('pin_resources.quantity', '>', 0)", $source);
        self::assertStringContainsString("->where('pin_prices.base_price', '>', 0)", $source);
        self::assertStringNotContainsString("->whereColumn('pin_resources.construction_resource_id', 'pin_prices.construction_resource_id')", $source);
        self::assertStringNotContainsString("->on('pin_prices.price_type', '=', 'pin_resources.resource_type')", $source);
        self::assertStringContainsString('->whereNotExists(function ($unpriced) use ($requested, $basePriceDatasetIds)', $source);
        self::assertStringContainsString('->whereNotExists(function ($validPrice) use ($requested, $basePriceDatasetIds)', $source);
        self::assertStringContainsString("->where('required_resources.quantity', '>', 0)", $source);
        self::assertStringContainsString("->where('required_resources.resource_type', '<>', 'summary')", $source);
        self::assertStringContainsString('->whereExists(function ($positiveQuantity)', $source);
        self::assertStringContainsString("->where('positive_resources.quantity', '>', 0)", $source);
        self::assertStringContainsString('->whereNotExists(function ($negativeQuantity)', $source);
        self::assertStringContainsString("->where('negative_resources.quantity', '<', 0)", $source);
        self::assertStringContainsString("->whereColumn('valid_prices.resource_code', 'required_resources.resource_code')", $source);
        self::assertStringContainsString('estimate_generation_unit_conversions as valid_conversions', $source);
        self::assertStringContainsString("->where('valid_conversions.version', 1)", $source);
        self::assertStringContainsString("->where('valid_conversions.is_active', true)", $source);
        self::assertStringContainsString("->where('valid_conversions.factor', '>', 0)", $source);
        self::assertStringContainsString('pin_prices.unit IS NOT DISTINCT FROM pin_resources.unit', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(pin_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString('valid_prices.unit IS NOT DISTINCT FROM required_resources.unit', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(valid_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString('candidate_prices.unit IS NOT DISTINCT FROM resources.unit', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(candidate_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString("'prices.unit as price_unit'", $source);
        self::assertStringContainsString("->where('resources.quantity', '>', 0)", $source);
        self::assertStringContainsString("->where('resources.resource_type', '<>', 'summary')", $source);
        self::assertStringContainsString("->where('quantity', '>', 0)", $source);
        self::assertStringContainsString('$this->ranker->select($query->all(), [$intent])', $source);
        self::assertStringContainsString("norms.search_vector @@ websearch_to_tsquery('russian', ?)", $source);
        self::assertStringContainsString("ts_rank_cd(norms.search_vector, websearch_to_tsquery('russian', ?)) AS pin_lexical_score", $source);
        self::assertStringContainsString("->orderByDesc('pin_lexical_score')", $source);
        self::assertStringContainsString('->limit(self::CANDIDATE_POOL_LIMIT)', $source);
        self::assertStringContainsString('CAST(norms.work_composition AS TEXT)', $source);
        self::assertStringContainsString("->where('source_type', 'fsnb_2022')", $source);
        self::assertStringContainsString("\$allowedSections->{\$method}('norms.section_code', 'like', \$section.'%')", $source);
        self::assertStringContainsString("latestPriceDatasetId('fsbc', true)", $source);
        self::assertStringContainsString("latestPriceDatasetId('fgis_labor_prices', false)", $source);
        self::assertStringContainsString('$fsbcBasePriceDatasetId,', $source);
        self::assertStringContainsString('$fgisLaborPriceDatasetId,', $source);
        self::assertStringContainsString('$requested->datasetId,', $source);
        self::assertStringContainsString("->whereIn('pin_prices.dataset_version_id', \$basePriceDatasetIds)", $source);
        self::assertStringContainsString("->whereIn('valid_prices.dataset_version_id', \$basePriceDatasetIds)", $source);
        self::assertStringContainsString('candidate_prices.dataset_version_id IN (', $source);
        self::assertStringContainsString('$basePricePlaceholders', $source);
        self::assertStringContainsString("->whereNull('regional_price_version_id')", $source);
        self::assertStringContainsString('basePriceDatasetIds', $source);
        self::assertStringContainsString('base_price_dataset_ids', $source);
        self::assertStringContainsString('code_matched_resource_rows_count', $source);
        self::assertStringContainsString('exact_unit_matched_resource_rows_count', $source);
        self::assertStringContainsString('normalized_unit_matched_resource_rows_count', $source);
        self::assertStringContainsString('diagnostic_lexical_candidates_count', $source);
        self::assertStringContainsString('unmatched_unit_pairs', $source);
        self::assertStringContainsString('diagnostic_pair_prices', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(diagnostic_normalized_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString("'resources.id as norm_resource_id'", $source);
        self::assertStringContainsString('resolveForIntents', $source);
        self::assertStringContainsString('CANDIDATE_POOL_LIMIT = 300', $source);
        self::assertStringContainsString('markersForAction', $source);
        self::assertStringContainsString('pin_semantic_priority', $source);
        self::assertStringNotContainsString("->orderBy('norms.id')->limit(129)", $source);
        self::assertStringNotContainsString("->where('norms.canonical_unit', \$unit)", $source);
    }

    #[Test]
    public function ranker_selects_candidate_from_the_preferred_normative_section(): void
    {
        $candidates = [
            (object) ['id' => 1, 'code' => '08-01-001-01', 'name' => 'Устройство покрытий', 'canonical_unit' => 'm2', 'unit' => 'm2', 'section_code' => '08'],
            (object) ['id' => 2, 'code' => '11-01-001-01', 'name' => 'Устройство покрытий', 'canonical_unit' => 'm2', 'unit' => 'm2', 'section_code' => '11'],
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Устройство покрытий', 'unit' => 'm2', 'code' => null, 'normative_section' => '11',
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function ranker_accepts_a_relevant_candidate_from_any_allowed_section(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) ['id' => 1, 'code' => '09-01-001-01', 'name' => 'Установка лестничных маршей', 'canonical_unit' => '100 pcs', 'unit' => '100 pcs', 'section_code' => '09'],
            (object) ['id' => 2, 'code' => '07-01-001-01', 'name' => 'Установка лестничных маршей', 'canonical_unit' => '100 pcs', 'unit' => '100 pcs', 'section_code' => '07'],
        ], [[
            'search_text' => 'Установка лестничных маршей', 'unit' => 'pcs', 'code' => null,
            'normative_sections' => ['06', '07', '08'],
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function ranker_keeps_relevance_order_instead_of_catalog_identifier_order(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 1, 'code' => '08-01-001-01', 'name' => 'Устройство конструкций стен',
                'canonical_unit' => 'm3', 'unit' => 'm3', 'section_code' => '08',
            ],
            (object) [
                'id' => 200, 'code' => '08-01-002-01', 'name' => 'Кладка наружных стен из газобетонных блоков',
                'canonical_unit' => 'm3', 'unit' => 'm3', 'section_code' => '08',
            ],
        ], [[
            'search_text' => 'Кладка наружных стен из газобетонных блоков',
            'unit' => 'm3', 'code' => null, 'normative_section' => '08',
        ]]);

        self::assertSame([200, 1], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function ranker_excludes_semantically_foreign_candidates_before_bounding_the_pinned_pool(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 1, 'code' => '09-01-001-01',
                'name' => 'Прокладка заземляющего проводника по строительным основаниям',
                'canonical_unit' => 'm', 'unit' => 'm', 'section_code' => '09',
            ],
            (object) [
                'id' => 2, 'code' => '09-01-002-01',
                'name' => 'Устройство временного ограждения строительной площадки',
                'canonical_unit' => 'm', 'unit' => 'm', 'section_code' => '09',
            ],
        ], [[
            'search_text' => 'Временное ограждение строительной площадки',
            'unit' => 'm', 'code' => null, 'action' => 'fence_installation',
            'normative_section' => '09',
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function explicitly_requested_normative_code_precedes_automatic_semantic_filter(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 10, 'code' => '09-01-001-01', 'name' => 'Специальная проектная норма',
                'canonical_unit' => 'm', 'unit' => 'm', 'section_code' => '09',
            ],
        ], [[
            'search_text' => 'Устройство временного ограждения', 'unit' => 'm',
            'code' => '09-01-001-01', 'action' => 'fence_installation', 'normative_section' => '09',
        ]]);

        self::assertSame([10], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function generic_finishing_word_cannot_match_wet_zone_tiling_to_facade_norm(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 1, 'code' => '15-02-009-01',
                'name' => 'Фактурная отделка фасадов стеклянной крошкой',
                'section_name' => 'Отделочные работы', 'section_code' => '15',
                'canonical_unit' => '100 m2', 'unit' => '100 m2',
            ],
            (object) [
                'id' => 2, 'code' => '15-01-019-05',
                'name' => 'Облицовка стен керамическими плитками',
                'section_name' => 'Отделочные работы', 'section_code' => '15',
                'canonical_unit' => '100 m2', 'unit' => '100 m2',
            ],
        ], [[
            'search_text' => 'Отделка мокрых зон плиткой',
            'unit' => 'm2', 'code' => null, 'normative_section' => '15',
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
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
    public function scaled_catalog_unit_is_compatible_with_work_item_unit(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([(object) [
            'id' => 101,
            'code' => '01-01-006-01',
            'name' => 'Разработка грунта в котлованах',
            'canonical_unit' => '1000 м3',
            'unit' => '1000 м3',
        ]], [[
            'search_text' => 'Разработка грунта под фундаменты',
            'unit' => 'm3',
            'code' => null,
        ]]);

        self::assertNotNull($selected);
        self::assertSame([101], array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
    }

    #[Test]
    public function russian_stems_match_inflected_norm_name(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([(object) [
            'id' => 202,
            'code' => '07-05-016-01',
            'name' => 'Монтаж лестничных маршей и площадок',
            'section_name' => 'Лестницы',
            'canonical_unit' => '100 шт',
            'unit' => '100 шт',
        ]], [[
            'search_text' => 'Устройство лестничных маршей',
            'unit' => 'шт',
            'code' => null,
        ]]);

        self::assertNotNull($selected);
        self::assertSame([202], array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
    }

    #[Test]
    public function sixty_four_distinct_intents_keep_a_bounded_candidate_pool(): void
    {
        $candidates = [];
        $intents = [];
        for ($intent = 1; $intent <= 64; $intent++) {
            $intents[] = ['search_text' => 'intentcode'.$intent, 'unit' => 'm2', 'code' => null];
            for ($variant = 1; $variant <= 3; $variant++) {
                $candidates[] = (object) [
                    'id' => ($intent * 10) + $variant,
                    'code' => 'code-'.$intent.'-'.$variant,
                    'name' => 'intentcode'.$intent.' variant'.$variant,
                    'canonical_unit' => '100 м2',
                    'unit' => '100 м2',
                ];
            }
        }

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, $intents);

        self::assertNotNull($selected);
        self::assertLessThanOrEqual(128, count($selected));
    }

    #[Test]
    public function unavailable_intent_does_not_discard_candidates_for_supported_intents(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([(object) [
            'id' => 101,
            'code' => '01-01-006-01',
            'name' => 'Разработка грунта в котлованах',
            'canonical_unit' => '1000 м3',
            'unit' => '1000 м3',
        ]], [
            ['search_text' => 'Разработка грунта под фундаменты', 'unit' => 'm3', 'code' => null],
            ['search_text' => 'Несуществующая специальная работа', 'unit' => 'компл', 'code' => null],
        ]);

        self::assertNotNull($selected);
        self::assertSame([101], array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
    }

    #[Test]
    public function pinned_candidate_preserves_object_type_for_residential_hard_gate(): void
    {
        $candidates = (new PinnedNormativeCandidateFactory)->forWorkItem([[
            'candidate_id' => '101', 'normative_id' => 101, 'dataset_id' => 77,
            'dataset_version' => 'v1', 'dataset_status' => 'parsed', 'code' => '20-01-001-01',
            'name' => 'Монтаж вентиляции офиса', 'unit' => 'м', 'section' => ['code' => '20'],
            'retrieval_metadata' => ['unit_dimension' => 'length', 'object_type' => 'office'],
        ]], ['name' => 'Монтаж вентиляции', 'normative_search_text' => 'Монтаж вентиляции', 'unit' => 'м']);

        self::assertSame('office', $candidates[0]->objectType);

        $intent = new WorkIntentData(
            1, 2, 3, 'work', 'Монтаж вентиляции', 'м', 'length', '', '', '', '',
            'residential', 'v1', 'parsed', null, new DateTimeImmutable('2026-07-01'), [],
        );
        $result = (new NormativeHardGate)->filter($intent, $candidates);

        self::assertSame([], $result->candidates);
        self::assertContains('object_type_mismatch', $result->rejected[0]->reasonCodes);
    }

    #[Test]
    public function database_resource_row_uses_the_authoritative_resource_code_relation(): void
    {
        $row = (object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => 501, 'price_construction_resource_id' => 502,
            'price_id' => 9001, 'resource_type' => 'material', 'resource_code' => '01.7.01',
            'price_resource_code' => '01.7.01', 'price_unit' => '100 pcs',
            'resource_name' => 'Кирпич', 'unit' => 'pcs', 'quantity' => 50,
            'unit_price' => '125.450000', 'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null, 'price_dataset_version' => null,
        ];
        $mapped = NormativeResourceRowData::fromDatabaseRow($row);

        self::assertSame(101, $mapped->estimateNormId);
        self::assertSame('materials', $mapped->group);
        self::assertSame(7001, $mapped->resource['norm_resource_id']);
        self::assertSame(9001, $mapped->resource['price_id']);
        self::assertSame(501, $mapped->resource['linked_resource_id']);
        self::assertSame('100 pcs', $mapped->resource['price_unit']);
        self::assertSame('125.450000', $mapped->resource['unit_price']);
        self::assertSame('regional_catalog', $mapped->resource['price_source']);
        self::assertSame('regional-2026-q2', $mapped->resource['price_source_version']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('normative_resource_price_relation_invalid');
        NormativeResourceRowData::fromDatabaseRow((object) [
            ...(array) $row,
            'price_resource_code' => '01.7.02',
        ]);
    }

    #[Test]
    public function exact_resource_code_links_norm_resource_to_regional_price_without_internal_resource_id(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => null,
            'price_id' => 9001, 'resource_type' => 'material',
            'resource_code' => '01.7.01', 'price_resource_code' => '01.7.01',
            'resource_name' => 'Кирпич', 'unit' => 'шт', 'quantity' => 50,
            'unit_price' => '125.450000', 'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null, 'price_dataset_version' => null,
        ]);

        self::assertSame(101, $mapped->estimateNormId);
        self::assertSame(9001, $mapped->resource['price_id']);
        self::assertNull($mapped->resource['linked_resource_id']);
    }

    #[Test]
    public function database_resource_row_preserves_fsnb_base_price_and_source(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => null,
            'price_id' => 9001, 'resource_type' => 'labor',
            'resource_code' => '1-100-01', 'price_resource_code' => '1-100-01',
            'resource_name' => 'Рабочий', 'unit' => 'чел.-ч', 'price_unit' => 'чел.-ч',
            'quantity' => '2.500000', 'unit_price' => '412.370000',
            'regional_price_version_id' => null, 'regional_price_version_key' => null,
            'price_dataset_source_type' => 'fsnb_2022', 'price_dataset_version' => '2022.4',
        ]);

        self::assertSame('412.370000', $mapped->resource['unit_price']);
        self::assertSame('fsnb_base', $mapped->resource['price_source']);
        self::assertSame('2022.4', $mapped->resource['price_source_version']);
    }

    #[Test]
    public function database_resource_row_preserves_fgis_labor_price_and_source(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => null,
            'price_id' => 9001, 'resource_type' => 'labor',
            'resource_code' => '1-100-01', 'price_resource_code' => '1-100-01',
            'resource_name' => 'Рабочий', 'unit' => 'чел.-ч', 'price_unit' => 'чел.-ч',
            'quantity' => '2.500000', 'unit_price' => '412.370000',
            'regional_price_version_id' => null, 'regional_price_version_key' => null,
            'price_dataset_source_type' => 'fgis_labor_prices', 'price_dataset_version' => '2026.2',
        ]);

        self::assertSame('fgis_labor_base', $mapped->resource['price_source']);
        self::assertSame('2026.2', $mapped->resource['price_source_version']);
    }
}
