<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\Integrations\EstimateGeneration\EstimateGenerationExcelExportService;
use App\Models\Estimate;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        self::assertCount(1, $data['sections'][1]['items']);
        self::assertSame('Concrete foundation', $data['sections'][1]['items'][0]['name']);
        self::assertSame('1', $data['sections'][1]['items'][0]['position_number']);
        self::assertCount(1, $data['sections'][1]['items'][0]['child_items']);
        self::assertSame('Concrete', $data['sections'][1]['items'][0]['child_items'][0]['name']);
        self::assertSame('1.1', $data['sections'][1]['items'][0]['child_items'][0]['position_number']);
        self::assertSame(1200.0, $data['sections'][1]['section_total_amount']);
    }

    public function test_native_xlsx_keeps_every_resource_once_with_distinct_units_prices_and_source_identity(): void
    {
        $service = (new ReflectionClass(EstimateGenerationExcelExportService::class))->newInstanceWithoutConstructor();
        $session = new EstimateGenerationSession(['id' => 44, 'organization_id' => 7, 'project_id' => 9, 'input_payload' => []]);
        $session->setRelation('project', null);
        $session->setRelation('organization', null);
        $definitions = [
            'M-KG' => ['materials', 'material', 'material:kg', 'kg', 1.0, 10.0, 10.0],
            'M-M3' => ['materials', 'material', 'material:m3', 'm3', 2.0, 5.0, 10.0],
            'MACHINE' => ['machinery', 'machinery', 'machinery:1', 'machine-h', 0.2, 50.0, 10.0],
            'LABOR' => ['labor', 'labor', 'labor:1', 'h', 0.5, 19.0, 9.5],
            'OTHER' => ['other_resources', 'other', 'other:1', 'count', 1.0, 30.0, 30.0],
        ];
        $work = ['key' => 'work:all-resources', 'name' => 'Работа со всеми ресурсами', 'item_type' => 'priced_work',
            'unit' => 'm2', 'quantity' => '2', 'total_cost' => '69.5', 'materials_cost' => '20', 'machinery_cost' => '10',
            'labor_cost' => '9.5', 'other_cost' => '30', 'pricing_status' => 'calculated', 'normative_rate_code' => 'WORK',
            'normative_match' => ['status' => 'matched', 'resources_count' => 5, 'priced_resources_count' => 5, 'decision' => ['status' => 'accepted']]];
        foreach ($definitions as $code => [$group, , $key, $unit, $quantity, $price, $total]) {
            $work[$group][] = ['key' => $key, 'name' => 'Одинаковое название', 'unit' => $unit, 'quantity' => $quantity,
                'unit_price' => $price, 'total_price' => $total, 'normative_ref' => ['resource_code' => $code]];
        }
        $data = (new ReflectionMethod($service, 'prepareExportData'))->invoke($service, $session, ['title' => 'Все ресурсы',
            'local_estimates' => [['title' => 'Дом', 'sections' => [['title' => 'Работы', 'work_items' => [$work]]]]]]);
        $estimate = new Estimate(['id' => 0, 'number' => 'AI-44']);
        $result = (new ExcelEstimateBuilder)->build($estimate, $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'most-native-resource-export-');
        self::assertNotFalse($path);
        $book = null;
        try {
            file_put_contents($path, $result['content']);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Смета');
            self::assertNotNull($sheet);
            $visible = [];
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                $code = (string) $sheet->getCell('B'.$row)->getValue();
                if (isset($definitions[$code])) {
                    $visible[$code][] = [(string) $sheet->getCell('D'.$row)->getValue(),
                        (float) $sheet->getCell('E'.$row)->getValue(), (float) $sheet->getCell('F'.$row)->getValue(),
                        (float) $sheet->getCell('G'.$row)->getValue()];
                }
            }
            self::assertCount(5, $visible);
            foreach ($definitions as $code => [, , , $unit, $quantity, $price, $total]) {
                self::assertSame([[$unit, $quantity, $price, $total]], $visible[$code]);
            }
            $metadataSheet = $book->getSheetByName('_METADATA_');
            self::assertNotNull($metadataSheet);
            $metadata = json_decode((string) $metadataSheet->getCell('A1')->getValue(), true, 64, JSON_THROW_ON_ERROR);
            self::assertCount(6, $metadata['items']);
            self::assertCount(6, array_unique(array_column($metadata['items'], 'id')));
            $resources = array_values(array_filter($metadata['items'], static fn (array $item): bool => $item['parent_work_id'] !== null));
            self::assertCount(5, $resources);
            foreach ($resources as $item) {
                [, $type, $key, $unit, $quantity, $price, $total] = $definitions[$item['normative_rate_code']];
                self::assertSame($type, $item['item_type']);
                self::assertSame($key, $item['metadata']['resource_key']);
                self::assertSame($unit, $item['measurement_unit']);
                self::assertEquals($quantity, $item['quantity_total']);
                self::assertEquals($price, $item['unit_price']);
                self::assertEquals($total, $item['total_amount']);
            }
            self::assertSame(69.5, array_sum(array_column($resources, 'total_amount')));
            self::assertSame(69.5, $data['totals']['total_amount']);
            self::assertSame(69.5, $data['sections'][1]['section_total_amount']);
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
    }
}
