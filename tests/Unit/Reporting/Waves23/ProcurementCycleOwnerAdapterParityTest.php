<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class ProcurementCycleOwnerAdapterParityTest extends TestCase
{
    public function test_every_owner_transition_records_the_matching_cycle_event(): void
    {
        $root = dirname(__DIR__, 4);
        $source = file_get_contents(
            $root.'/app/BusinessModules/Features/Procurement/Reporting/ProcurementReportingLifecycleRecorder.php',
        );
        self::assertIsString($source);

        foreach ([
            "'request_created'",
            "'request_approved'",
            "'solicitation_sent'",
            "'supplier_responded'",
            "'award_decided'",
            "'order_sent'",
            "'first_receipt'",
            "'fully_received'",
            "'cancelled'",
        ] as $eventCode) {
            self::assertStringContainsString($eventCode, $source);
        }
        self::assertStringContainsString('SupplyLifecycleEvent', file_get_contents(
            $root.'/app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/'
            .'ProcurementCycleSnapshotMaterializer.php',
        ));
    }
}
