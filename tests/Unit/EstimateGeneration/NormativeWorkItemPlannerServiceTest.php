<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class NormativeWorkItemPlannerServiceTest extends TestCase
{
    public function test_fsbc_resource_reference_stays_review_item_without_work_norm_code(): void
    {
        $items = $this->planner()->build(
            [
                'key' => 'materials',
                'scope_type' => 'custom',
                'sections' => [[
                    'key' => 'materials-section',
                    'title' => 'Материалы',
                    'construction_part' => 'custom',
                ]],
            ],
            [
                'key' => 'materials-section',
                'title' => 'Материалы',
                'construction_part' => 'custom',
            ],
            [
                'source_documents' => [[
                    'id' => 83,
                    'filename' => 'material-specification.pdf',
                    'status' => 'ready',
                    'quality' => ['level' => 'good'],
                    'text' => 'ФСБЦ 01.1.01.01-0001 Бетон тяжелый 12 м3',
                    'document_understanding' => [
                        'role_for_estimation' => 'quantity_source',
                    ],
                ]],
            ]
        );

        $resourceItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['metadata']['normative_resource_code'] ?? null) === '01.1.01.01-0001'
        ))[0] ?? null;

        self::assertIsArray($resourceItem);
        self::assertSame('priced_work', $resourceItem['item_type']);
        self::assertNull($resourceItem['normative_rate_code']);
        self::assertSame('01.1.01.01-0001', $resourceItem['metadata']['normative_resource_code']);
        self::assertSame('fsbc_resource', $resourceItem['metadata']['normative_reference_kind']);
        self::assertTrue($resourceItem['metadata']['requires_work_norm_selection']);
        self::assertContains('normative_code_required', $resourceItem['validation_flags']);
        self::assertSame('not_calculated', $resourceItem['pricing_status']);
        self::assertSame('normative_required', $resourceItem['pricing_blocker']);
        self::assertSame(12.0, $resourceItem['quantity']);
        self::assertSame('м3', $resourceItem['unit']);
        self::assertSame(83, $resourceItem['source_refs'][0]['document_id']);
    }

    private function planner(): NormativeWorkItemPlannerService
    {
        return new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor(),
            new EstimatorScopeInferenceService(),
        );
    }
}
