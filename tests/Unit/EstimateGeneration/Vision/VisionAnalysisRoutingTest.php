<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisionAnalysisRoutingTest extends TestCase
{
    #[Test]
    public function literal_provider_payload_preserves_bounded_routing_and_regions(): void
    {
        $analysis = VisionAnalysisData::fromProviderArray([
            'schema_version' => 4,
            'sheet_type' => 'detail',
            'evidence' => [['key' => 'page', 'locator' => [
                'page_id' => 17,
                'page_number' => 2,
                'processing_unit_id' => 19,
                'source_version' => 'sha256:'.str_repeat('a', 64),
                'coordinate_space' => 'normalized_derivative_v1',
            ]]],
            'elements' => [],
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
            'visual_attributes' => [],
            'project_sheet_analysis' => [
                'contractVersion' => 'sheet-analysis:v3',
                'role' => 'unknown',
                'facts' => [],
            ],
            'analysis_routing' => [
                'page_kind' => 'drawing',
                'requested_depth' => 'dense_ambiguous',
                'information_density' => 'high',
                'readability' => 'medium',
                'confidence' => 0.91,
                'ambiguous' => true,
                'material_risk' => true,
                'reasons' => ['Мелкие размеры требуют увеличения.'],
                'semantic_regions' => [[
                    'label' => 'Размеры узла',
                    'purpose' => 'microtext',
                    'box' => [0.2, 0.2, 0.7, 0.6],
                ]],
            ],
        ], 'timeweb', 'openai/gpt-5.6-luna', 'openai/gpt-5.6-luna', 'vision-v4', 'measured', 100, 30, 64);

        self::assertSame('dense_ambiguous', $analysis->analysisRouting?->effectiveRoute->value);
        self::assertSame('Размеры узла', $analysis->analysisRouting?->semanticRegions[0]['label']);
        self::assertSame(4, $analysis->toArray()['schema_version']);
    }
}
