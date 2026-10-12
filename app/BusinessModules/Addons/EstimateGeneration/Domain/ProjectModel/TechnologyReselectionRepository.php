<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

interface TechnologyReselectionRepository
{
    public function applyTechnologyReselection(Decision $decision, Fact $selectedFact, string $expectedFactId,
        string $expectedDecisionId, string $inputFingerprint): bool;
}
