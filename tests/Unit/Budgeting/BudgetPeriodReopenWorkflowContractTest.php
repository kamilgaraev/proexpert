<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use PHPUnit\Framework\TestCase;

final class BudgetPeriodReopenWorkflowContractTest extends TestCase
{
    public function test_reopen_service_uses_transaction_row_lock_and_closed_status_guard(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/BusinessModules/Features/Budgeting/Services/BudgetPeriodReopenService.php'
        );

        $this->assertStringContainsString('DB::transaction', $source);
        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString('canReopenStatus($previousStatus)', $source);
        $this->assertStringContainsString('BudgetPeriodClosure::create', $source);
        $this->assertStringContainsString("'previous_closure_uuid'", $source);
        $this->assertStringContainsString("'allowed_operations'", $source);
        $this->assertStringContainsString("'reopened_until'", $source);
    }

    public function test_reopen_logic_is_not_kept_in_catalog_service(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/BusinessModules/Features/Budgeting/Services/BudgetCatalogService.php'
        );

        $this->assertStringContainsString('BudgetPeriodReopenService', $source);
        $this->assertStringNotContainsString('BudgetPeriodClosure::create', $source);
        $this->assertStringNotContainsString("'closure_status' => 'reopened_for_adjustment'", $source);
    }

    public function test_reclose_persists_reopen_history_and_management_snapshot(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/BusinessModules/Features/Budgeting/Services/BudgetPeriodClosureService.php'
        );

        $this->assertStringContainsString("'reclosed_after_reopen'", $source);
        $this->assertStringContainsString('managementActualizationSnapshot($lockedPeriod)', $source);
        $this->assertStringContainsString("'previous_closure_uuid'", $source);
        $this->assertStringContainsString("'source_of_truth'", $source);
    }
}
