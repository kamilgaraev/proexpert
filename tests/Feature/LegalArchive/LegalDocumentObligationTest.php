<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentObligationTest extends TestCase
{
    public function test_effective_document_sync_contract_is_declared(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Obligations/LegalDocumentObligationService.php');
        $lifecycle = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/LegalArchiveLifecycleService.php');

        self::assertStringContainsString('syncFromEffectiveDocument', $source);
        self::assertStringContainsString('updateOrCreate', $source);
        self::assertStringContainsString('syncFromEffectiveDocument($locked)', $lifecycle);
    }

    public function test_execution_contract_keeps_assignee_and_evidence_separate_from_the_document_draft(): void
    {
        $execution = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Obligations/LegalDocumentObligationExecutionService.php');
        $resource = (string) file_get_contents(dirname(__DIR__, 3).'/app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentResource.php');

        self::assertStringContainsString('responsible_user_id', $execution);
        self::assertStringContainsString('evidence', $execution);
        self::assertStringContainsString('completed_at', $execution);
        self::assertStringContainsString('legal_obligation_completed_immutable', $execution);
        self::assertStringContainsString("record('obligation_completed'", $execution);
        self::assertStringContainsString("'responsible_user'", $resource);
        self::assertStringContainsString("'evidence'", $resource);
    }
}
