<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use PHPUnit\Framework\TestCase;

class JournalWarehouseContractTest extends TestCase
{
    public function test_draft_materials_do_not_write_off_stock_and_approval_commits_once(): void
    {
        $journalService = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/ConstructionJournalService.php'
        );
        $approvalService = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/JournalApprovalService.php'
        );

        preg_match(
            '/protected function attachMaterials\(.*?\n    }\n\n    public function commitMaterialConsumption/s',
            $journalService,
            $attachMaterialsMatch
        );

        self::assertNotEmpty($attachMaterialsMatch);
        self::assertStringNotContainsString('writeOffJournalMaterialFromCustody', $attachMaterialsMatch[0]);
        self::assertStringContainsString('commitMaterialConsumption(', $journalService);
        self::assertStringContainsString('$this->journalService->commitMaterialConsumption($entry)', $approvalService);
        self::assertStringContainsString("whereNull('warehouse_movement_id')", $journalService);
    }
}
