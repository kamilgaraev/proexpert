<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class SupplyRound15OwnershipTest extends TestCase
{
    public function test_cycle_owner_is_captured_before_process_projection(): void
    {
        $recorder = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/'
            .'ProcurementProcessEventRecorder.php',
        );
        $backfill = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Backfill/'
            .'ProcurementCycleBackfill.php',
        );

        self::assertStringContainsString('$this->captureOwnerExpectation(', $recorder);
        self::assertStringContainsString("'purchase_request_line:'.\$purchaseRequestLineId.':owner'", $recorder);
        self::assertStringContainsString('$this->events->captureOwnerExpectation(', $backfill);
        self::assertLessThan(
            strpos($backfill, "if (\$request->created_at === null)"),
            strpos($backfill, '$this->events->captureOwnerExpectation('),
        );
    }

    public function test_award_uses_authoritative_request_owner_dimensions(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Award/Services/'
            .'SupplierAwardDecisionVersionRecorder.php',
        );

        self::assertStringContainsString('$authoritativePurchaseRequestId', $source);
        self::assertStringContainsString('$authoritativeProjectId', $source);
        self::assertStringContainsString("'project_id' => \$authoritativeProjectId", $source);
        self::assertStringContainsString(
            "'purchase_request_id' => \$authoritativePurchaseRequestId",
            $source,
        );
    }

    public function test_supply_membership_uses_immutable_promises_only(): void
    {
        $materializer = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Services/'
            .'SupplyReliabilitySnapshotMaterializer.php',
        );
        $readiness = $this->source(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Readiness/'
            .'SupplyReliabilityReadinessProbe.php',
        );

        self::assertStringNotContainsString('PurchaseOrderItem::query()', $materializer);
        self::assertStringNotContainsString('owner_site_request', $materializer);
        self::assertStringNotContainsString('readiness_owner_order', $readiness);
        self::assertStringContainsString('$missingPromise = max(0, $eligible - $projected)', $readiness);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).DIRECTORY_SEPARATOR.$path);
        self::assertIsString($source);

        return $source;
    }
}
