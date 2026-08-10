<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

final readonly class TargetedSheetRecheckPlan
{
    public function __construct(
        public TargetedSheetRecheckScope $scope,
        public ?TargetedSheetEvidence $supplementalEvidence,
    ) {}
}
