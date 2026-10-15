<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\Integrations\EstimateGeneration\EstimateGenerationExcelExportService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Admin\DashboardExportService;
use App\Services\EstimatePositionCatalog\ImportExportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class SpreadsheetExportFormulaSecurityTest extends TestCase
{
    public function test_all_six_export_paths_treat_user_formulas_as_literal_text(): void
    {
        $position = $this->position('=1+1');
        $catalog = new ImportExportService;
        $catalogBook = $this->readFile($catalog->exportToExcel($position->organization_id));
        $catalogCell = $catalogBook->getActiveSheet()->getCell('B2');
        $actual['catalog_xlsx'] = [$catalogCell->getDataType(), $catalogCell->getValue()];
        $catalogBook->disconnectWorksheets();
        $actual['catalog_csv'] = $this->csvRows($this->readAndDelete($catalog->exportToCsv($position->organization_id)))[1][1];

        $project = $this->project('=1+1');
        $dashboardBook = $this->readContent($this->dashboard('buildProjectsXlsx', $project));
        $dashboardCell = $dashboardBook->getActiveSheet()->getCell('B2');
        $actual['dashboard_xlsx'] = [$dashboardCell->getDataType(), $dashboardCell->getValue()];
        $dashboardBook->disconnectWorksheets();
        $actual['dashboard_csv'] = $this->csvRows($this->dashboard('buildProjectsCsv', $project), ';')[1][1];

        $session = $this->generationSession($project, '=1+1');
        $result = (new EstimateGenerationExcelExportService(new ExcelEstimateBuilder))->export($session);
        $generationBook = $this->readContent($result['content']);
        $generationCell = $this->findCell($generationBook, '=1+1');
        $actual['generation_xlsx'] = [$generationCell->getDataType(), $generationCell->getValue()];
        $generationBook->disconnectWorksheets();
        $actual['generation_csv'] = $this->csvRows($this->generationCsv($session, $project))[1][6];

        self::assertSame([
            'catalog_xlsx' => [DataType::TYPE_STRING, '=1+1'],
            'catalog_csv' => "'=1+1",
            'dashboard_xlsx' => [DataType::TYPE_STRING, '=1+1'],
            'dashboard_csv' => "'=1+1",
            'generation_xlsx' => [DataType::TYPE_STRING, '=1+1'],
            'generation_csv' => "'=1+1",
        ], $actual);
    }

    public function test_csv_exports_neutralize_alternate_prefixes_without_changing_other_values(): void
    {
        $position = $this->position('Контроль');
        $dangerous = ['=1+1', '+1+1', '-1+1', '@SUM(1)', "\t=1+1", "\r=1+1", "\n=1+1", '  =1+1', "\u{FEFF}=1+1", "\u{00A0}@SUM(1)", '＝1+1', "=HYPERLINK(\"https://example.test/a,b;c\",\"строка\nдве\")"];
        $unchanged = ['Обычный текст', '00123', '12,50', '12.50', '-45.67', '-0.25', '2026-10-15', "'=@готовый текст", "строка\nдве,три;четыре", 'безопасный\\",=1+1', 'безопасный\\";=1+1'];

        foreach (array_merge($dangerous, $unchanged) as $text) {
            $position->update(['name' => $text]);
            $project = $this->project($text);
            $expected = in_array($text, $dangerous, true) ? "'".$text : $text;
            $catalogRows = $this->csvRows($this->readAndDelete((new ImportExportService)->exportToCsv($position->organization_id)));
            self::assertCount(2, $catalogRows);
            self::assertCount(9, $catalogRows[1]);
            $catalogExpected = $text === '12.50' ? '12.5' : str_replace(["\r\n", "\r"], "\n", $expected);
            self::assertSame($catalogExpected, $catalogRows[1][1], 'catalog '.json_encode($text));
            self::assertEquals(45.67, (float) $catalogRows[1][5]);
            self::assertSame('00123', $catalogRows[1][0]);
            $dashboardContent = $this->dashboard('buildProjectsCsv', $project);
            self::assertStringStartsWith("\xEF\xBB\xBF", $dashboardContent);
            $dashboardRows = $this->csvRows($dashboardContent, ';');
            self::assertCount(2, $dashboardRows);
            self::assertCount(9, $dashboardRows[1]);
            self::assertSame($expected, $dashboardRows[1][1], 'dashboard '.json_encode($text));
            self::assertEquals(-37.5, (float) $dashboardRows[1][7]);
            self::assertSame('2026-10-15', $dashboardRows[1][5]);
            $generationRows = $this->csvRows($this->generationCsv($this->generationSession($project, $text), $project));
            self::assertCount(2, $generationRows);
            self::assertCount(7, $generationRows[1]);
            self::assertSame($expected, $generationRows[1][6], 'generation '.json_encode($text));
            self::assertSame('10', $generationRows[1][4]);
            self::assertSame('1200', $generationRows[1][5]);
        }
    }

    public function test_xlsx_exports_preserve_numbers_codes_metadata_and_original_text(): void
    {
        $text = '=SUM(1,2)';
        $position = $this->position($text);
        $catalog = $this->readFile((new ImportExportService)->exportToExcel($position->organization_id));
        try {
            $sheet = $catalog->getActiveSheet();
            self::assertSame('00123', $sheet->getCell('A2')->getValue());
            self::assertSame($text, $sheet->getCell('B2')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
            self::assertSame('=2+2', $sheet->getCell('C2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('F2')->getDataType());
            self::assertEquals(45.67, $sheet->getCell('F2')->getValue());
        } finally {
            $catalog->disconnectWorksheets();
        }
        $project = $this->project($text);
        $dashboard = $this->readContent($this->dashboard('buildProjectsXlsx', $project));
        try {
            $sheet = $dashboard->getActiveSheet();
            self::assertSame($text, $sheet->getCell('B2')->getValue());
            self::assertSame($text, $sheet->getCell('C2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('H2')->getDataType());
            self::assertEquals(-37.5, $sheet->getCell('H2')->getValue());
        } finally {
            $dashboard->disconnectWorksheets();
        }
        $session = $this->generationSession($project, $text);
        $result = (new EstimateGenerationExcelExportService(new ExcelEstimateBuilder))->export($session);
        $generation = $this->readContent($result['content']);
        try {
            $cell = $this->findCell($generation, $text);
            self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
            $work = $this->findCell($generation, 'Бетонирование фундамента');
            $sheet = $generation->getActiveSheet();
            foreach (['E' => 10, 'F' => 120, 'G' => 1200] as $column => $number) {
                $value = $sheet->getCell($column.$work->getRow());
                self::assertSame(DataType::TYPE_NUMERIC, $value->getDataType());
                self::assertEquals($number, $value->getValue());
            }
            $metadata = $generation->getSheetByName('_METADATA_');
            self::assertSame('hidden', $metadata->getSheetState());
            $decoded = json_decode($metadata->getCell('A1')->getValue(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($text, $decoded['items'][0]['description']);
            self::assertEquals(10, $decoded['items'][0]['quantity_total']);
            foreach ($generation->getAllSheets() as $worksheet) {
                foreach ($worksheet->getCellCollection()->getCoordinates() as $coordinate) {
                    self::assertNotSame(DataType::TYPE_FORMULA, $worksheet->getCell($coordinate)->getDataType());
                }
            }
        } finally {
            $generation->disconnectWorksheets();
        }
    }

    private function position(string $text): EstimatePositionCatalog
    {
        $organization = Organization::factory()->create();
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id, 'name' => '=3+3',
            'short_name' => 'csv-security', 'type' => 'material',
        ]);

        return EstimatePositionCatalog::query()->create([
            'organization_id' => $organization->id,
            'measurement_unit_id' => $unit->id,
            'created_by_user_id' => User::factory()->create()->id,
            'name' => $text, 'code' => '00123', 'description' => '=2+2', 'item_type' => 'work',
            'unit_price' => 45.67, 'direct_costs' => 12.5, 'overhead_percent' => 10,
            'profit_percent' => 5, 'is_active' => true, 'usage_count' => 3,
        ]);
    }

    private function project(string $text): Project
    {
        $project = new Project;
        $project->forceFill(['id' => 5, 'organization_id' => 7, 'name' => $text, 'address' => $text,
            'budget_amount' => 123.5, 'status' => 'active', 'start_date' => '2026-10-15',
            'latitude' => '-37.500000', 'longitude' => '45.250000']);
        $project->setRelation('organization', new Organization(['name' => 'Организация']));

        return $project;
    }

    private function generationSession(Project $project, string $basis): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill(['id' => 13, 'organization_id' => 7, 'project_id' => 5,
            'status' => EstimateGenerationStatus::ReadyToApply, 'input_payload' => [],
            'draft_payload' => ['title' => 'Смета', 'local_estimates' => [['title' => 'Фундамент',
                'sections' => [['title' => 'Бетонные работы', 'work_items' => [[
                    'name' => 'Бетонирование фундамента', 'item_type' => 'priced_work',
                    'unit' => 'м3', 'quantity' => 10, 'total_cost' => 1200,
                    'quantity_basis' => $basis, 'pricing_status' => 'calculated',
                    'normative_rate_code' => '01-01-001-01',
                    'normative_match' => ['status' => 'matched', 'resources_count' => 1,
                        'priced_resources_count' => 1, 'decision' => ['status' => 'accepted']],
                    'materials' => [['name' => 'Бетон', 'unit' => 'м3', 'quantity' => 10,
                        'unit_price' => 120, 'total_price' => 1200]],
                ]]]],
            ]]]]);
        $session->setRelation('project', $project);
        $session->setRelation('organization', $project->organization);

        return $session;
    }

    private function dashboard(string $method, Project $project): string
    {
        $service = (new ReflectionClass(DashboardExportService::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod($service, $method))->invoke($service, collect([$project]));
    }

    private function generationCsv(EstimateGenerationSession $session, Project $project): string
    {
        $request = Request::create('/export?format=csv');
        $actor = new User;
        $actor->forceFill(['id' => 11, 'current_organization_id' => 7]);
        $request->setUserResolver(static fn (): User => $actor);
        $response = $this->app->make(EstimateGenerationPackageController::class)->export($request, $project, $session);
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function csvRows(string $content, string $delimiter = ','): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, separator: $delimiter, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function readAndDelete(string $path): string
    {
        try {
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function readFile(string $path): Spreadsheet
    {
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function readContent(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'most-formula-security-');
        file_put_contents($path, $content);

        return $this->readFile($path);
    }

    private function findCell(Spreadsheet $spreadsheet, string $text): \PhpOffice\PhpSpreadsheet\Cell\Cell
    {
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if ($cell->getValue() === $text) {
                return $cell;
            }
        }
        self::fail('Expected exported text: '.$text);
    }
}
