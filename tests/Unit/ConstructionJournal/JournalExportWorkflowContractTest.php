<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JournalExportWorkflowContractTest extends TestCase
{
    #[Test]
    public function journal_exports_are_queued_idempotent_and_pollable(): void
    {
        $root = dirname(__DIR__, 3);
        $service = file_get_contents($root.'/app/Services/ConstructionJournal/JournalExportWorkflowService.php');
        $job = file_get_contents($root.'/app/Jobs/ConstructionJournal/GenerateJournalExportJob.php');
        $adminRoutes = file_get_contents($root.'/routes/api/construction-journal.php');
        $mobileRoutes = file_get_contents($root.'/routes/api/v1/mobile/construction_journal.php');

        self::assertStringContainsString('firstOrCreate', $service);
        self::assertStringContainsString('idempotency_key', $service);
        self::assertStringContainsString('GenerateJournalExportJob::dispatch', $service);
        self::assertStringContainsString('ShouldQueue', $job);
        self::assertStringContainsString('ShouldBeUnique', $job);
        self::assertStringContainsString('uniqueId(): string', $job);
        self::assertStringContainsString("'progress' => 100", $job);
        self::assertStringContainsString("'status' => JournalExport::STATUS_FAILED", $job);
        self::assertStringContainsString("'export_too_large'", $job);
        self::assertStringContainsString("Route::get('construction-journal-exports/{export}'", $adminRoutes);
        self::assertStringContainsString("Route::get('/construction-journal-exports/{export}'", $mobileRoutes);
    }

    #[Test]
    public function generated_files_have_immutable_request_specific_paths(): void
    {
        $service = file_get_contents(dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/BudgetEstimates/Services/Export/OfficialFormsExportService.php');

        self::assertStringContainsString('?string $exportId = null', $service);
        self::assertStringContainsString('$this->uniqueExportFilename(', $service);
        self::assertStringContainsString('->lazy(200)', $service);
        self::assertStringContainsString('assertPdfExportSize', $service);
    }
}
