<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationExcelExportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class EstimateGenerationExcelExportServiceTest extends TestCase
{
    public function test_export_data_skips_service_rows_from_draft_work_items(): void
    {
        $service = (new ReflectionClass(EstimateGenerationExcelExportService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'prepareExportData');
        $method->setAccessible(true);

        $session = new EstimateGenerationSession([
            'id' => 44,
            'organization_id' => 7,
            'project_id' => 9,
            'input_payload' => ['description' => 'Test estimate'],
        ]);
        $session->setRelation('project', null);
        $session->setRelation('organization', null);

        $data = $method->invoke($service, $session, [
            'title' => 'AI estimate',
            'local_estimates' => [[
                'key' => 'foundation',
                'title' => 'Foundation',
                'totals' => ['total_cost' => 1200],
                'sections' => [[
                    'key' => 'foundation.main',
                    'title' => 'Foundation works',
                    'work_items' => [[
                        'key' => 'foundation.operation',
                        'item_type' => 'operation',
                        'name' => 'Prepare work front',
                        'total_cost' => 0,
                    ], [
                        'key' => 'foundation.quantity-review',
                        'item_type' => 'quantity_review',
                        'name' => 'Confirm foundation volume',
                        'unit' => 'm3',
                        'quantity' => 10,
                        'total_cost' => 0,
                        'normative_rate_code' => '01-01-001-01',
                    ], [
                        'key' => 'foundation.not-calculated',
                        'item_type' => 'priced_work',
                        'name' => 'Unresolved foundation work',
                        'unit' => 'm3',
                        'quantity' => 10,
                        'pricing_status' => 'not_calculated',
                        'total_cost' => 0,
                        'normative_rate_code' => '01-01-001-01',
                    ], [
                        'key' => 'foundation.concrete',
                        'item_type' => 'priced_work',
                        'name' => 'Concrete foundation',
                        'unit' => 'm3',
                        'quantity' => 10,
                        'total_cost' => 1200,
                        'pricing_status' => 'calculated',
                        'normative_rate_code' => '01-01-001-01',
                        'normative_match' => [
                            'status' => 'matched',
                            'resources_count' => 1,
                            'priced_resources_count' => 1,
                            'decision' => ['status' => 'accepted'],
                        ],
                        'materials' => [[
                            'name' => 'Concrete',
                            'unit' => 'm3',
                            'quantity' => 10,
                            'unit_price' => 120,
                            'total_price' => 1200,
                            'normative_ref' => ['resource_code' => '01.1.01.01-0001'],
                        ]],
                    ], [
                        'key' => 'foundation.review-priced',
                        'item_type' => 'priced_work',
                        'name' => 'Foundation needs norm review',
                        'unit' => 'm3',
                        'quantity' => 10,
                        'total_cost' => 1200,
                        'pricing_status' => 'calculated_review_required',
                        'normative_rate_code' => '01-01-001-01',
                    ], [
                        'key' => 'foundation.zero-price',
                        'item_type' => 'priced_work',
                        'name' => 'Zero price foundation',
                        'unit' => 'm3',
                        'quantity' => 10,
                        'total_cost' => 0,
                        'normative_rate_code' => '01-01-001-01',
                    ], [
                        'key' => 'foundation.without-norm',
                        'item_type' => 'priced_work',
                        'name' => 'Ungrounded foundation',
                        'unit' => 'm3',
                        'quantity' => 10,
                        'total_cost' => 1200,
                    ], [
                        'key' => 'foundation.note',
                        'item_type' => 'review_note',
                        'name' => 'Needs review',
                        'total_cost' => 0,
                    ]],
                ]],
            ]],
        ]);

        self::assertIsArray($data);
        self::assertCount(2, $data['sections'][1]['items']);
        self::assertSame('Concrete foundation', $data['sections'][1]['items'][0]['name']);
        self::assertSame('1', $data['sections'][1]['items'][0]['position_number']);
        self::assertSame('Concrete', $data['sections'][1]['items'][1]['name']);
        self::assertSame('1.1', $data['sections'][1]['items'][1]['position_number']);
        self::assertSame(1200.0, $data['sections'][1]['section_total_amount']);
    }
}
