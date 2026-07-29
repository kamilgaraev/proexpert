<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class ContractorScorecardContractTest extends TestCase
{
    public function test_objective_sources_are_contractor_specific_and_drilldown_repeats_authorization(): void
    {
        $root = dirname(__DIR__, 7);
        $reader = file_get_contents($root.'/app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/Services/ContractorScorecardObservationReader.php');
        $drill = file_get_contents($root.'/app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/DrillDown/ContractorScorecardDrillDownProvider.php');

        self::assertIsString($reader);
        self::assertIsString($drill);
        self::assertStringContainsString('$profileByContractor[$contractorId]', $reader);
        self::assertStringContainsString('ReportSourceObjectAuthorizer', $drill);
        self::assertStringContainsString("'availability' => 'redacted'", $drill);
    }
}
