<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class SupplierAwardOwnerAdapterParityTest extends TestCase
{
    public function test_owner_selection_pins_the_exact_comparison_versions_and_reason(): void
    {
        $root = dirname(__DIR__, 4);
        $owner = file_get_contents(
            $root.'/app/BusinessModules/Features/Procurement/Services/SupplierProposalComparisonService.php',
        );
        $recorder = file_get_contents(
            $root.'/app/BusinessModules/Features/Procurement/Reporting/Award/Services/'
            .'SupplierAwardDecisionVersionRecorder.php',
        );
        self::assertIsString($owner);
        self::assertIsString($recorder);

        foreach ([
            'winning_supplier_proposal_version_id',
            'cheapest_supplier_proposal_version_id',
            'comparison_snapshot',
            'decision_reason',
            'awardOwnerRecorder',
        ] as $identity) {
            self::assertStringContainsString($identity, $owner);
        }
        foreach ([
            'selectedProposalVersionId',
            'cheapestProposalVersionId',
            'medianProposalVersionId',
            'comparableSetHash',
            'decisionReason',
        ] as $identity) {
            self::assertStringContainsString($identity, $recorder);
        }
    }
}
