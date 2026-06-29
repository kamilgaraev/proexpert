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
                        'key' => 'foundation.concrete',
                        'item_type' => 'priced_work',
                        'name' => 'Concrete foundation',
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
        self::assertCount(1, $data['sections'][1]['items']);
        self::assertSame('Concrete foundation', $data['sections'][1]['items'][0]['name']);
        self::assertSame('1', $data['sections'][1]['items'][0]['position_number']);
        self::assertSame(1200.0, $data['sections'][1]['section_total_amount']);
    }
}
