<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class HandoverReadinessContractTest extends TestCase
{
    public function test_hard_blockers_document_reversals_and_source_redaction_are_bound(): void
    {
        $root = dirname(__DIR__, 7);
        $formula = file_get_contents($root.'/app/BusinessModules/Features/HandoverAcceptance/Reporting/Readiness/Services/HandoverReadinessFormula.php');
        $drill = file_get_contents($root.'/app/BusinessModules/Features/HandoverAcceptance/Reporting/Readiness/DrillDown/HandoverReadinessDrillDownProvider.php');

        self::assertIsString($formula);
        self::assertIsString($drill);
        self::assertStringContainsString('document_approval_reversed', $formula);
        self::assertStringContainsString('blocker_reopened', $formula);
        self::assertStringContainsString("'availability' => 'redacted'", $drill);
    }
}
