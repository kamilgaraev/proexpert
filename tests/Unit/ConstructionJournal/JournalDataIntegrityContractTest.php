<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

class JournalDataIntegrityContractTest extends TestCase
{
    public function test_requested_volume_cannot_exceed_estimate_or_contract_remaining_quantity(): void
    {
        $reflection = new ReflectionClass(JournalApprovalService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('assertQuantityAvailable');

        $method->invoke($service, 4.0, 10.0, 6.0, 10.0, 6.0);
        self::addToAssertionCount(1);

        $this->expectException(Throwable::class);
        $method->invoke($service, 4.001, 10.0, 6.0, 10.0, 6.0);
    }

    public function test_entry_contract_does_not_accept_coverage_mutation_and_keeps_resource_links(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3)
            .'/app/Http/Requests/ConstructionJournal/StoreJournalEntryRequest.php');
        self::assertStringContainsString("'work_volumes.*.auto_attach_contract_coverage' => 'prohibited'", $source);
        self::assertStringContainsString("'materials.*.estimate_item_id'", $source);
        self::assertStringContainsString("'equipment.*.estimate_item_id'", $source);
        self::assertStringContainsString("'workers.*.estimate_item_id'", $source);
    }

    public function test_service_enforces_estimate_semantics_and_schedule_item_compatibility(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/ConstructionJournalService.php'
        );

        self::assertStringNotContainsString('ensureCoverage($entry->journal', $source);
        self::assertStringContainsString('assertEntryEstimateConsistency(', $source);
        self::assertStringContainsString('assertScheduleTaskVolumeCompatibility(', $source);
        self::assertStringContainsString("->where('status', 'approved')", $source);
    }
}
