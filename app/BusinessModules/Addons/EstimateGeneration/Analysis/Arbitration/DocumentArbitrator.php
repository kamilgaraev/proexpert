<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;

interface DocumentArbitrator
{
    /** @param array<string,AiRoleRunResult> $observerRuns */
    public function run(VisionDocumentInput $source, array $observerRuns): AiRoleRunResult;
}
