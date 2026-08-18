<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CanonicalDocumentFactPresenter;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EstimateGenerationDocumentSemanticProjectionTest extends TestCase
{
    #[Test]
    public function production_page_five_becomes_deduplicated_human_readable_admin_facts(): void
    {
        $fixture = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/session-75-page-5-canonicalization.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $presenter = new CanonicalDocumentFactPresenter(static fn (string $key): string => [
            'estimate_generation.canonical_result.overall_width' => 'Габарит здания по оси X',
            'estimate_generation.canonical_result.overall_height' => 'Габарит здания по оси Y',
            'estimate_generation.canonical_result.grid_span' => 'Пролёт между осями :axes',
            'estimate_generation.canonical_result.unknown_dimension' => 'Размер на чертеже',
            'estimate_generation.canonical_result.elevation' => 'Высотная отметка',
            'estimate_generation.canonical_result.floor_count' => 'Этажность',
            'estimate_generation.canonical_result.total_area' => 'Общая площадь',
            'estimate_generation.canonical_result.wall' => 'Конструкция стены',
            'estimate_generation.canonical_result.material' => 'Материал',
            'estimate_generation.canonical_result.work_scope' => 'Конструктивное решение',
            'estimate_generation.canonical_result.fact' => 'Результат разбора',
            'estimate_generation.canonical_result.floor_one' => 'этаж',
            'estimate_generation.canonical_result.floor_few' => 'этажа',
            'estimate_generation.canonical_result.floor_many' => 'этажей',
            'estimate_generation.canonical_result.axes_unknown' => 'не указаны',
            'estimate_generation.canonical_result.yes' => 'да',
            'estimate_generation.canonical_result.no' => 'нет',
        ][$key] ?? $key);
        $page = (object) [
            'page_number' => 5,
            'normalized_payload' => $fixture,
        ];

        $payload = (new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis'))
            ->invoke(null, $page, $presenter);
        $labels = array_column($payload['facts'], 'label');

        self::assertSame(1, count(array_keys($labels, 'Кухня-гостиная — 22,10 м²', true)));
        self::assertContains('Габарит здания по оси X — 11 100 мм', $labels);
        self::assertContains('Пролёт между осями А–Д — 11 100 мм', $labels);
        self::assertContains('Габарит здания по оси Y — 7 300 мм', $labels);
        self::assertContains('Размер на чертеже — 2 500 мм', $labels);
        self::assertContains('Высотная отметка — ±0,000 м', $labels);
        self::assertContains('Этажность — 1 этаж', $labels);
        self::assertContains('Наружная стена — 229 мм', $labels);
        self::assertNotContains('Наружная стена — 229 мм мм', $labels);
        self::assertCount(3, $payload['quarantined_items']);
        self::assertLessThan(1.0, $payload['facts'][0]['confidence']);
        self::assertCount(3, array_values(array_filter(
            $payload['facts'],
            static fn (array $fact): bool => $fact['label'] === 'Кухня-гостиная — 22,10 м²',
        ))[0]['lineage']);
        self::assertNotContains('level', $labels);
        self::assertNotContains('dimension_chain', $labels);
        self::assertNotContains('room', $labels);
        self::assertNotContains('wall', $labels);
    }

    #[Test]
    public function it_projects_bounded_document_semantics_without_questions_or_raw_provider_response(): void
    {
        $page = (object) ['normalized_payload' => [
            'vision_analysis' => [
                'elements' => [[
                    'type' => 'roof',
                    'label' => 'gable',
                    'confidence' => 0.94,
                    'evidence_ref' => 'page-1',
                    'polygon' => [[0.1, 0.1], [0.2, 0.2]],
                ]],
                'project_sheet_analysis' => [
                    'role' => 'facade',
                    'facts' => [[
                        'entityKey' => 'finish-unknown',
                        'factType' => 'unknown_material',
                        'value' => ['type' => 'unknown', 'data' => null],
                    ]],
                ],
            ],
            'semantic_quality' => [
                'role' => 'facade',
                'checked' => ['roof_geometry', 'material'],
                'found' => ['roof_geometry'],
                'missing' => ['material'],
                'needs_targeted' => ['material'],
                'quarantined_items' => [['section' => 'elements', 'index' => 13, 'reason' => 'invalid_element']],
            ],
            'analysis_routing' => [
                'route' => 'dense_ambiguous',
                'reasons' => ['dense_drawing'],
                'observer_roles' => ['observer_literal', 'observer_construction', 'observer_risk'],
                'arbiter_required' => true,
                'physical_provider_call_count' => 4,
                'semantic_regions' => [['id' => 'region-1', 'label' => 'Размерная цепочка']],
            ],
            'analysis_outcome' => 'ready_calculation',
            'role_completion' => [
                'observer_literal' => true,
                'observer_construction' => true,
                'observer_risk' => true,
                'arbiter' => true,
            ],
            'provider_raw_response' => 'must-not-leak',
        ]];

        $payload = (new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis'))
            ->invoke(null, $page);

        self::assertSame('facade', $payload['role']);
        self::assertSame('gable', $payload['observations'][0]['label']);
        self::assertArrayNotHasKey('questions', $payload);
        self::assertSame(['material'], $payload['coverage']['needs_targeted']);
        self::assertTrue($payload['analysis_complete']);
        self::assertSame('dense_ambiguous', $payload['route']);
        self::assertSame(4, $payload['physical_provider_call_count']);
        self::assertSame('Размерная цепочка', $payload['semantic_regions'][0]['label']);
        self::assertArrayNotHasKey('provider_raw_response', $payload);
    }

    #[Test]
    public function legacy_empty_or_oversized_question_choices_cannot_break_document_read(): void
    {
        $questions = [
            ['code' => 'empty_choices', 'choices' => []],
            ['code' => 'too_many_choices', 'choices' => range(1, 9)],
        ];
        $page = (object) ['normalized_payload' => [
            'ai_questions' => $questions,
            'document_arbitration' => ['questions' => $questions],
            'vision_analysis' => ['elements' => [['type' => 'wall', 'label' => 'brick']]],
        ]];

        $payload = (new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis'))
            ->invoke(null, $page);

        self::assertSame('brick', $payload['observations'][0]['label']);
        self::assertArrayNotHasKey('questions', $payload);
    }

    #[Test]
    public function malformed_single_observation_is_isolated_without_losing_valid_page_results(): void
    {
        $page = (object) [
            'page_number' => 2,
            'normalized_payload' => [
                'vision_analysis' => ['elements' => [
                    ['type' => 'wall', 'label' => 'Кирпичная стена'],
                    'damaged-observation',
                ]],
                'independent_observations' => [
                    'observer_literal' => ['claims' => [
                        ['entityKey' => 'wall:1', 'factType' => 'material', 'value' => ['type' => 'string', 'data' => 'brick']],
                        ['entityKey' => null, 'factType' => 'material'],
                    ]],
                ],
            ],
        ];

        $payload = (new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis'))
            ->invoke(null, $page);

        self::assertSame('Кирпичная стена', $payload['observations'][0]['label']);
        self::assertSame('wall:1', $payload['context'][0]['entityKey']);
        self::assertSame(['malformed_observation', 'malformed_observation'], array_column($payload['limitations'], 'code'));
        self::assertSame(2, $payload['limitations'][0]['source_locator']['page_number']);
    }

    #[Test]
    public function one_observer_context_page_is_complete_without_fake_missing_roles(): void
    {
        $page = (object) ['normalized_payload' => [
            'analysis_outcome' => 'ready_context',
            'analysis_routing' => [
                'route' => 'simple_context',
                'observer_roles' => ['observer_literal'],
                'arbiter_required' => false,
                'physical_provider_call_count' => 1,
            ],
            'role_completion' => [
                'observer_literal' => true,
                'observer_construction' => false,
                'observer_risk' => false,
                'arbiter' => false,
            ],
            'independent_observations' => [
                'observer_literal' => ['claims' => [[
                    'entityKey' => 'document-section',
                    'factType' => 'section_name',
                    'value' => ['type' => 'string', 'data' => 'Архитектурные решения (АР)'],
                    'unit' => null,
                    'evidenceRef' => 'title-region',
                ]]],
            ],
        ]];

        $payload = (new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis'))
            ->invoke(null, $page);

        self::assertTrue($payload['analysis_complete']);
        self::assertSame('ready_context', $payload['outcome']);
        self::assertSame(1, $payload['physical_provider_call_count']);
        self::assertSame('Архитектурные решения (АР)', $payload['context'][0]['value']['data']);
    }
}
