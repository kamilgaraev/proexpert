<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use PHPUnit\Framework\TestCase;

class JournalIntentContractTest extends TestCase
{
    public function test_create_and_submit_is_one_idempotent_server_transaction(): void
    {
        $workflow = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/JournalEntryWorkflowService.php'
        );

        self::assertStringContainsString('DB::transaction(', $workflow);
        self::assertStringContainsString('lockForUpdate()', $workflow);
        self::assertStringContainsString('payload_fingerprint', $workflow);
        self::assertStringContainsString('submit_after_create', $workflow);
        self::assertStringContainsString('submitForApproval(', $workflow);
    }

    public function test_entry_schema_persists_idempotency_and_complete_review_history(): void
    {
        $entryMigration = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_08_22_010000_add_workflow_integrity_to_construction_journal_entries.php'
        );
        $historyMigration = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_08_22_010001_create_journal_entry_approval_events_table.php'
        );
        $approval = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/JournalApprovalService.php'
        );

        self::assertMatchesRegularExpression(
            "/unique\(\s*\['journal_id', 'created_by_user_id', 'idempotency_key'\]/",
            $entryMigration
        );
        self::assertStringContainsString("'payload_fingerprint'", $entryMigration);
        self::assertStringContainsString("Schema::create('journal_entry_approval_events'", $historyMigration);
        self::assertGreaterThanOrEqual(3, substr_count($approval, 'recordApprovalEvent('));
    }
}
