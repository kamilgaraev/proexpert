<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use PHPUnit\Framework\TestCase;

class JournalLifecycleContractTest extends TestCase
{
    public function test_journal_lifecycle_and_delete_guards_are_server_owned(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/ConstructionJournalService.php'
        );

        self::assertStringContainsString("'status' => JournalStatusEnum::ACTIVE", $service);
        self::assertStringContainsString('$journal->entries()->withTrashed()->exists()', $service);
        self::assertStringContainsString('closeJournal(', $service);
        self::assertStringContainsString('archiveJournal(', $service);
        self::assertStringContainsString('reopenJournal(', $service);

        $routes = (string) file_get_contents(dirname(__DIR__, 3).'/routes/api/construction-journal.php');
        self::assertStringContainsString("Route::post('close'", $routes);
        self::assertStringContainsString("Route::post('archive'", $routes);
        self::assertStringContainsString("Route::post('reopen'", $routes);
    }

    public function test_http_contract_prohibits_direct_status_assignment(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3)
            .'/app/Http/Requests/ConstructionJournal/StoreConstructionJournalRequest.php');
        self::assertStringContainsString("'status' => ['prohibited']", $source);
    }

    public function test_policies_enforce_journal_organization_and_active_state(): void
    {
        $journalPolicy = (string) file_get_contents(dirname(__DIR__, 3).'/app/Policies/ConstructionJournalPolicy.php');
        $entryPolicy = (string) file_get_contents(dirname(__DIR__, 3).'/app/Policies/ConstructionJournalEntryPolicy.php');

        self::assertStringContainsString('hasJournalAccess(', $journalPolicy);
        self::assertStringContainsString('hasJournalAccess(', $entryPolicy);
        self::assertStringContainsString('JournalStatusEnum::ACTIVE', $entryPolicy);
    }
}
