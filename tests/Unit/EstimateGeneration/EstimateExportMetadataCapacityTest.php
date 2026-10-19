<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\Integrations\EstimateGeneration\EstimateGenerationExcelExportService;
use App\Models\Estimate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class EstimateExportMetadataCapacityTest extends TestCase
{
    public function test_large_multisection_xlsx_preserves_all_resource_identities_and_unicode_without_cell_truncation(): void
    {
        $session = new EstimateGenerationSession(['id' => 44, 'organization_id' => 7, 'project_id' => 9, 'input_payload' => []]);
        $session->setRelation('project', null);
        $session->setRelation('organization', null);
        $sections = [];
        $expected = [];
        $definitions = [
            ['materials', 'material', 'kg', 1.0, 10.0, 10.0],
            ['materials', 'material', 'm3', 2.0, 5.0, 10.0],
            ['machinery', 'machinery', 'machine-h', 0.2, 50.0, 10.0],
            ['labor', 'labor', 'h', 0.5, 19.0, 9.5],
            ['other_resources', 'other', 'pcs', 1.0, 30.0, 30.0],
        ];
        for ($sectionIndex = 1; $sectionIndex <= 10; $sectionIndex++) {
            $items = [];
            for ($workIndex = 1; $workIndex <= 4; $workIndex++) {
                $key = 'capacity:'.$sectionIndex.':'.$workIndex;
                $work = ['key' => $key, 'name' => 'Тестовая работа '.$key, 'item_type' => 'priced_work', 'unit' => 'm2',
                    'quantity' => '2', 'total_cost' => '69.5', 'materials_cost' => '20', 'machinery_cost' => '10',
                    'labor_cost' => '9.5', 'other_cost' => '30', 'pricing_status' => 'calculated', 'normative_rate_code' => $key,
                    'normative_match' => ['status' => 'matched', 'resources_count' => 5, 'priced_resources_count' => 5, 'decision' => ['status' => 'accepted']]];
                foreach ($definitions as $index => [$group, $type, $unit, $quantity, $price, $total]) {
                    $code = $key.':resource:'.$index;
                    $resourceKey = 'resource:'.$code;
                    $name = 'Одинаковое название 🏠 '.str_repeat('Материал ', 8);
                    $work[$group][] = ['key' => $resourceKey, 'name' => $name, 'unit' => $unit, 'quantity' => $quantity,
                        'unit_price' => $price, 'total_price' => $total, 'normative_ref' => ['resource_code' => $code]];
                    $expected[$code] = [$type, $resourceKey, $name, $unit, $quantity, $price, $total];
                }
                $items[] = $work;
            }
            $sections[] = ['title' => 'Тестовый раздел '.$sectionIndex, 'work_items' => $items];
        }
        $service = (new ReflectionClass(EstimateGenerationExcelExportService::class))->newInstanceWithoutConstructor();
        $data = (new ReflectionMethod($service, 'prepareExportData'))->invoke($service, $session,
            ['title' => 'Тест ёмкости экспорта', 'local_estimates' => [['title' => 'Синтетическая многораздельная смета', 'sections' => $sections]]]);
        $result = (new ExcelEstimateBuilder)->build(new Estimate(['id' => 0, 'number' => 'AI-44']), $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'most-export-capacity-');
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
                if (isset($expected[$code])) {
                    self::assertSame('#,##0.00##########', $sheet->getStyle('F'.$row)->getNumberFormat()->getFormatCode());
                    $visible[$code][] = [(string) $sheet->getCell('D'.$row)->getValue(), (float) $sheet->getCell('E'.$row)->getValue(),
                        (float) $sheet->getCell('F'.$row)->getValue(), (float) $sheet->getCell('G'.$row)->getValue()];
                }
            }
            self::assertCount(200, $visible);
            foreach ($expected as $code => [, , , $unit, $quantity, $price, $total]) {
                self::assertSame([[$unit, $quantity, $price, $total]], $visible[$code]);
            }
            $metadataSheet = $book->getSheetByName('_METADATA_');
            self::assertNotNull($metadataSheet);
            self::assertSame('hidden', $metadataSheet->getSheetState());
            $manifest = json_decode((string) $metadataSheet->getCell('A1')->getValue(), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('3.0', $manifest['version']);
            self::assertSame('utf8-json-chunks', $manifest['encoding']);
            self::assertGreaterThan(1, $manifest['chunk_count']);
            $json = '';
            for ($row = 2; $row <= $manifest['chunk_count'] + 1; $row++) {
                $cell = $metadataSheet->getCell('A'.$row);
                self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
                $chunk = (string) $cell->getValue();
                self::assertTrue(mb_check_encoding($chunk, 'UTF-8'));
                self::assertLessThanOrEqual(30000, strlen($chunk));
                $json .= $chunk;
            }
            self::assertGreaterThan(32767, mb_strlen($json));
            self::assertSame($manifest['byte_length'], strlen($json));
            self::assertSame($manifest['sha256'], hash('sha256', $json));
            $metadata = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('2.0', $metadata['version']);
            self::assertCount(11, $metadata['sections']);
            self::assertCount(240, $metadata['items']);
            self::assertCount(240, array_unique(array_column($metadata['items'], 'id')));
            $resources = array_values(array_filter($metadata['items'], static fn (array $item): bool => $item['parent_work_id'] !== null));
            self::assertCount(200, $resources);
            foreach ($resources as $item) {
                [$type, $key, $name, $unit, $quantity, $price, $total] = $expected[$item['normative_rate_code']];
                self::assertSame($type, $item['item_type']);
                self::assertSame($key, $item['metadata']['resource_key']);
                self::assertSame($name, $item['name']);
                self::assertSame($unit, $item['measurement_unit']);
                self::assertEquals($quantity, $item['quantity_total']);
                self::assertEquals($price, $item['unit_price']);
                self::assertEquals($total, $item['total_amount']);
            }
            self::assertSame(2780.0, array_sum(array_column($resources, 'total_amount')));
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
    }
}
