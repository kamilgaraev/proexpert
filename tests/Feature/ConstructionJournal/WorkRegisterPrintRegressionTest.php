<?php

declare(strict_types=1);

namespace Tests\Feature\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkRegisterPrintRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_fits_all_columns_on_one_printed_page_width(): void
    {
        $sheet = $this->export(3, 'short');

        self::assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        self::assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
        self::assertTrue($sheet->getPageSetup()->getFitToPage());
        self::assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        self::assertSame(0, $sheet->getPageSetup()->getFitToHeight());
        self::assertSame('A1:H'.$sheet->getHighestRow(), $sheet->getPageSetup()->getPrintArea());
        self::assertSame(['8', '8'], $sheet->getPageSetup()->getRowsToRepeatAtTop());
        $text = json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringContainsString('APPROVED-01', $text);
        self::assertStringContainsString('APPROVED-03', $text);
        self::assertStringNotContainsString('PRIVATE-DRAFT', $text);
        self::assertStringNotContainsString('OUTSIDE-PERIOD', $text);
        self::assertStringContainsString('Утверждено', $text);
        self::assertStringContainsString('Ответственный за ведение журнала', $text);
    }

    public function test_long_report_preserves_all_rows_and_vertical_pagination(): void
    {
        $sheet = $this->export(35, 'long');
        self::assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        self::assertSame(0, $sheet->getPageSetup()->getFitToHeight());
        self::assertSame(['8', '8'], $sheet->getPageSetup()->getRowsToRepeatAtTop());
        $text = json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        for ($number = 1; $number <= 35; $number++) {
            self::assertStringContainsString('APPROVED-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT), $text);
        }
        self::assertStringNotContainsString('PRIVATE-DRAFT', $text);
    }

    private function export(int $count, string $name): Worksheet
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Учебный ПТО — ремонт офиса 100 м² — проверка печати журнала',
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Учебный журнал печатной проверки',
            'journal_number' => 'PRINT-102',
            'start_date' => '2026-10-01',
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        for ($number = 1; $number <= $count; $number++) {
            ConstructionJournalEntry::query()->create([
                'journal_id' => $journal->id,
                'entry_date' => '2026-10-13',
                'entry_number' => $number,
                'work_description' => 'APPROVED-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).' Учебная подготовка и отделка поверхности стен. Длинное описание сохраняется полностью и должно переноситься в пределах своей графы при печати.',
                'status' => 'approved',
                'created_by_user_id' => $context->user->id,
                'approved_by_user_id' => $context->user->id,
                'approved_at' => now(),
                'weather_conditions' => ['temperature' => 20, 'precipitation' => 'Работы внутри помещения, без осадков'],
            ]);
        }
        foreach ([['draft', '2026-10-13', 'PRIVATE-DRAFT'], ['approved', '2026-09-30', 'OUTSIDE-PERIOD']] as $index => [$status, $date, $description]) {
            ConstructionJournalEntry::query()->create([
                'journal_id' => $journal->id,
                'entry_date' => $date,
                'entry_number' => $count + $index + 1,
                'work_description' => $description,
                'status' => $status,
                'created_by_user_id' => $context->user->id,
                'approved_by_user_id' => $status === 'approved' ? $context->user->id : null,
                'approved_at' => $status === 'approved' ? now() : null,
            ]);
        }

        $path = app(OfficialFormsExportService::class)->exportKS6ToExcel($journal, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        Storage::disk('s3')->assertExists($path);
        $debugDirectory = getenv('MOST_EXPORT_DEBUG_DIR');
        if (is_string($debugDirectory) && $debugDirectory !== '') {
            if (! is_dir($debugDirectory)) {
                mkdir($debugDirectory, 0777, true);
            }
            file_put_contents($debugDirectory.'/'.$name.'.xlsx', Storage::disk('s3')->get($path));
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'most-register-');
        self::assertNotFalse($temporaryPath);
        try {
            self::assertTrue(copy(Storage::disk('s3')->path($path), $temporaryPath));

            return IOFactory::load($temporaryPath)->getActiveSheet();
        } finally {
            unlink($temporaryPath);
        }
    }
}
