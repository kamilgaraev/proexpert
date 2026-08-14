<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;

interface DocumentObserverRunner
{
    /** @param list<ObserverProfile> $profiles @return array<string,AiRoleRunResult> */
    public function run(VisionDocumentInput $source, array $profiles): array;
}
