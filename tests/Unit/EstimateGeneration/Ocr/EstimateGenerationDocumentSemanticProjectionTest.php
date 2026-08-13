<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
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
            'provider_raw_response' => 'must-not-leak',
        ]];
        $method = new ReflectionMethod(EstimateGenerationDocumentDetailResource::class, 'semanticAnalysis');

        $payload = $method->invoke(null, $page);

        self::assertSame('facade', $payload['role']);
        self::assertSame('gable', $payload['observations'][0]['label']);
        self::assertCount(1, $payload['questions']);
        self::assertSame(['material'], $payload['coverage']['needs_targeted']);
        self::assertArrayNotHasKey('provider_raw_response', $payload);
    }
}
