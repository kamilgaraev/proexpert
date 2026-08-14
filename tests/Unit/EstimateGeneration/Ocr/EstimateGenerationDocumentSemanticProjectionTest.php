<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ClarificationQuestionProjector;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EstimateGenerationDocumentSemanticProjectionTest extends TestCase
{
    #[Test]
    public function it_projects_bounded_operator_semantics_without_raw_provider_response(): void
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
                        'entityKey' => 'finish-question',
                        'factType' => 'unresolved_question',
                        'value' => ['type' => 'string', 'data' => 'confirm finish'],
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
            'ai_questions' => [[
                'code' => 'confirm_finish',
                'subject' => 'Отделка фасада',
                'reason' => 'Материал не указан явно.',
                'impact' => 'Состав работ нельзя подтвердить.',
                'recommendation' => 'Уточнить материал фасада.',
                'choices' => ['Штукатурка'],
                'source_locator' => ['evidence_refs' => ['page-1']],
            ]],
            'provider_raw_response' => 'must-not-leak',
        ]];
        $container = new Container;
        Container::setInstance($container);
        $container->instance(
            ClarificationQuestionProjector::class,
            new ClarificationQuestionProjector(static fn (string $key): string => str_ends_with($key, '.other')
                ? 'Другой вариант'
                : 'Оставить без решения'),
        );
        $method = new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis');

        $payload = $method->invoke(null, $page);

        self::assertSame('facade', $payload['role']);
        self::assertSame('gable', $payload['observations'][0]['label']);
        self::assertCount(1, $payload['questions']);
        self::assertSame(['material'], $payload['coverage']['needs_targeted']);
        self::assertTrue($payload['analysis_complete']);
        self::assertSame('dense_ambiguous', $payload['route']);
        self::assertSame(4, $payload['physical_provider_call_count']);
        self::assertSame('Размерная цепочка', $payload['semantic_regions'][0]['label']);
        self::assertArrayNotHasKey('provider_raw_response', $payload);
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
        $method = new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis');

        $payload = $method->invoke(null, $page);

        self::assertTrue($payload['analysis_complete']);
        self::assertSame('ready_context', $payload['outcome']);
        self::assertSame(1, $payload['physical_provider_call_count']);
        self::assertSame('Архитектурные решения (АР)', $payload['context'][0]['value']['data']);
    }
}
