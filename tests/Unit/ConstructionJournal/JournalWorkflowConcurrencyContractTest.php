<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalScheduleIntegrationService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class JournalWorkflowConcurrencyContractTest extends TestCase
{
    public function test_rejected_entry_can_be_resubmitted_but_terminal_states_cannot(): void
    {
        self::assertTrue(JournalEntryStatusEnum::DRAFT->canSubmit());
        self::assertTrue(JournalEntryStatusEnum::REJECTED->canSubmit());
        self::assertFalse(JournalEntryStatusEnum::SUBMITTED->canSubmit());
        self::assertFalse(JournalEntryStatusEnum::APPROVED->canSubmit());
    }

    public function test_workflow_commands_lock_entry_and_publish_after_commit_with_one_schedule_owner(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/JournalApprovalService.php'
        );
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/BudgetEstimatesServiceProvider.php'
        );

        self::assertGreaterThanOrEqual(3, substr_count($service, '$this->lockJournalAndEntry($entry)'));
        self::assertStringContainsString('lockForUpdate()', $service);
        self::assertStringContainsString('DB::afterCommit(', $service);
        self::assertStringNotContainsString('updateTaskProgressFromEntry(', $service);
        self::assertStringContainsString('UpdateScheduleProgressFromJournal::class', $provider);
    }

    public function test_journal_mutations_recheck_lifecycle_under_consistent_locks(): void
    {
        $journalService = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/ConstructionJournalService.php'
        );
        $workflowService = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/JournalEntryWorkflowService.php'
        );

        self::assertGreaterThanOrEqual(2, substr_count($journalService, '$this->lockJournalAndEntry($entry)'));
        self::assertStringContainsString('assertEntryEditable($entry)', $journalService);
        self::assertStringContainsString('approvalEvents()->exists()', $journalService);
        self::assertStringContainsString('entries()->withTrashed()->exists()', $journalService);
        self::assertStringContainsString('assertJournalActive($journal)', $workflowService);
    }

    public function test_quantity_reservation_and_approval_permission_are_contract_and_project_scoped(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/JournalApprovalService.php'
        );

        self::assertStringContainsString('$contractReserved', $source);
        self::assertStringContainsString("where('contract_id', (int) \$contractId)", $source);
        self::assertStringContainsString("'project_id' => (int) \$journal->project_id", $source);
        self::assertStringContainsString('$this->authorizationService->can(', $source);

        $reflection = new ReflectionClass(JournalApprovalService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $remaining = $reflection->getMethod('remainingQuantity');
        self::assertSame(10.0, $remaining->invoke($service, 100.0, 90.0, 50.0, 10.0));
        self::assertSame(5.0, $remaining->invoke($service, 100.0, 20.0, 50.0, 45.0));
    }

    public function test_schedule_actual_dates_are_derived_from_chronological_confirmed_facts(): void
    {
        $facts = collect([
            (object) ['completion_date' => '2026-08-20', 'completed_quantity' => 4],
            (object) ['completion_date' => '2026-08-10', 'completed_quantity' => 6],
            (object) ['completion_date' => '2026-08-15', 'completed_quantity' => 2],
        ]);
        $reflection = new ReflectionClass(JournalScheduleIntegrationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('calculateActualDates');

        $dates = $method->invoke($service, $facts, 10.0);

        self::assertSame('2026-08-10', CarbonImmutable::parse($dates['start'])->toDateString());
        self::assertSame('2026-08-20', CarbonImmutable::parse($dates['end'])->toDateString());
    }
}
