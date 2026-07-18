<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use PHPUnit\Framework\TestCase;

final class SoilHaulageSafetyContractTest extends TestCase
{
    public function test_soil_haulage_is_treated_as_earthwork_at_search_and_decision_boundaries(): void
    {
        $root = dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/';
        $search = file_get_contents($root.'NormativeCandidateSearchService.php');
        $decision = file_get_contents($root.'NormativeMatchDecisionService.php');

        self::assertStringContainsString("['excavation', 'backfill', 'soil_haulage']", $search);
        self::assertStringContainsString("['excavation', 'backfill', 'soil_haulage']", $decision);
    }
}
