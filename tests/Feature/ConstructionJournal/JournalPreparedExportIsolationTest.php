<?php

declare(strict_types=1);

namespace Tests\Feature\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Services\Storage\FileService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class JournalPreparedExportIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('exportCases')]
    public function test_direct_preparations_keep_previous_content_and_background_export_ids_keep_their_path(string $report, string $format, string $directory): void
    {
        $this->travelTo(Carbon::parse('2026-10-24T03:00:00+03:00'));
        $disk = Storage::fake('journal-export-isolation');
        $this->mock(FileService::class, function ($mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk);
        });
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'name' => 'PREPARED-FIRST Учебный проект']);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Учебный журнал проверки сохранности выгрузок',
            'journal_number' => 'EXPORT-102',
            'start_date' => '2026-10-01',
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'entry_date' => '2026-10-13',
            'entry_number' => 1,
            'work_description' => 'PREPARED-FIRST Учебная работа',
            'status' => 'approved',
            'created_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'approved_at' => now(),
        ]);
        $service = app(OfficialFormsExportService::class);
        $first = $this->prepare($service, $journal, $entry, $report, $format);
        $firstHash = hash('sha256', $disk->get($first));
        self::assertStringContainsString('PREPARED-FIRST', $this->documentText($disk->path($first), $format));

        $entry->update(['work_description' => 'PREPARED-SECOND Учебная работа']);
        $project->update(['name' => 'PREPARED-SECOND Учебный проект']);
        $journal->unsetRelation('project');
        $entry->unsetRelation('journal');
        $second = $this->prepare($service, $journal, $entry, $report, $format);

        self::assertNotSame($first, $second);
        self::assertSame(basename($first), basename($second));
        self::assertStringStartsWith('org-'.$context->organization->id.'/exports/journal/'.$directory.'/', $first);
        self::assertSame(5, substr_count($first, '/'));
        self::assertSame($firstHash, hash('sha256', $disk->get($first)));
        self::assertStringContainsString('PREPARED-SECOND', $this->documentText($disk->path($second), $format));

        $exportId = 'test-background-export-102';
        $background = $this->prepare($service, $journal, $entry, $report, $format, $exportId);
        self::assertSame('org-'.$context->organization->id.'/exports/journal/'.$directory.'/'.pathinfo($first, PATHINFO_FILENAME).'_'.$exportId.'.'.$format, $background);
        self::assertSame($background, $this->prepare($service, $journal, $entry, $report, $format, $exportId));
        self::assertSame($firstHash, hash('sha256', $disk->get($first)));
        self::assertCount(3, $disk->allFiles());
    }

    public static function exportCases(): array
    {
        return [
            'register xlsx' => ['register', 'xlsx', 'ks6'],
            'register pdf' => ['register', 'pdf', 'ks6'],
            'daily pdf' => ['daily', 'pdf', 'daily'],
            'extended xlsx' => ['extended', 'xlsx', 'extended'],
            'extended pdf' => ['extended', 'pdf', 'extended'],
        ];
    }

    private function prepare(OfficialFormsExportService $service, ConstructionJournal $journal, ConstructionJournalEntry $entry, string $report, string $format, ?string $exportId = null): string
    {
        return match ($report.'-'.$format) {
            'register-xlsx' => $service->exportKS6ToExcel($journal, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), null, $exportId),
            'register-pdf' => $service->exportKS6ToPdf($journal, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), null, $exportId),
            'daily-pdf' => $service->exportDailyReportToPdf($entry, $exportId),
            'extended-xlsx' => $service->exportExtendedReportToExcel($journal, ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], $exportId),
            'extended-pdf' => $service->exportExtendedReportToPdf($journal, ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], $exportId),
        };
    }

    private function documentText(string $path, string $format): string
    {
        if ($format === 'pdf') {
            return (new Parser)->parseFile($path)->getText();
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'most-journal-');
        self::assertNotFalse($temporaryPath);
        try {
            self::assertTrue(copy($path, $temporaryPath));
            $spreadsheet = IOFactory::load($temporaryPath);
            try {
                return json_encode($spreadsheet->getActiveSheet()->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } finally {
            unlink($temporaryPath);
        }
    }
}
