<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\AnalysisMaterialRisk;
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
                'material_risk' => 'high',
                'reasons' => ['Мелкие размеры требуют увеличения.'],
                'semantic_regions' => [[
                    'label' => 'Размеры узла',
                    'purpose' => 'microtext',
                    'box' => [0.2, 0.2, 0.7, 0.6],
                ]],
            ],
        ], 'timeweb', 'openai/gpt-5.6-luna', 'openai/gpt-5.6-luna', 'vision-v4', 'measured', 100, 30, 64);

        self::assertSame('dense_ambiguous', $analysis->analysisRouting?->effectiveRoute->value);
        self::assertSame(AnalysisMaterialRisk::High, $analysis->analysisRouting?->materialRisk);
        self::assertSame('Размеры узла', $analysis->analysisRouting?->semanticRegions[0]['label']);
        self::assertSame(4, $analysis->toArray()['schema_version']);
    }

    #[Test]
    public function legacy_boolean_material_risk_is_rejected_instead_of_coerced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('page_analysis_routing_schema_invalid');

        \App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => 'title',
            'requested_depth' => 'simple_context',
            'information_density' => 'low',
            'readability' => 'high',
            'confidence' => 0.99,
            'ambiguous' => false,
            'material_risk' => false,
            'reasons' => ['Лёгкая страница.'],
            'semantic_regions' => [],
        ]);
    }

    #[Test]
    public function provider_evidence_quarantines_duplicate_or_malformed_items_without_losing_valid_evidence(): void
    {
        $locator = [
            'page_id' => 17,
            'page_number' => 2,
            'processing_unit_id' => 19,
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'coordinate_space' => 'normalized_derivative_v1',
        ];
        $analysis = VisionAnalysisData::fromProviderArray([
            'schema_version' => 4,
            'sheet_type' => 'detail',
            'evidence' => [
                ['key' => 'page', 'locator' => [...$locator, 'optional_provider_field' => true]],
                ['key' => 'page', 'locator' => $locator],
                ['key' => 'broken', 'locator' => ['page_number' => 2]],
            ],
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
                'requested_depth' => 'simple_context',
                'information_density' => 'low',
                'readability' => 'high',
                'confidence' => 0.99,
                'ambiguous' => false,
                'material_risk' => 'low',
                'reasons' => ['Контроль изоляции отдельных значений.'],
                'semantic_regions' => [],
            ],
        ], 'recorded', 'openai/gpt-5.6-luna', 'openai/gpt-5.6-luna', 'vision-v4', 'measured', 100, 30, 64);

        self::assertSame(['page'], array_map(static fn ($item): string => $item->key, $analysis->evidence));
        self::assertSame(
            ['duplicate_evidence_key', 'invalid_evidence'],
            array_column($analysis->quarantinedItems, 'reason'),
        );
    }

    #[Test]
    public function fail_open_uses_high_material_risk_without_changing_the_ambiguity_type(): void
    {
        $decision = \App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisRoutingDecision::failOpen(
            'invalid_routing_contract',
        );

        self::assertTrue($decision->ambiguous);
        self::assertSame(AnalysisMaterialRisk::High, $decision->materialRisk);
        self::assertSame('high', $decision->toArray()['material_risk']);
    }
}
