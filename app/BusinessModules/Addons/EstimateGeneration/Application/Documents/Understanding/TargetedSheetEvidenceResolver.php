<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetEvidence;

interface TargetedSheetEvidenceResolver
{
    public function resolvePeer(DocumentUnitExecutionContext $context, string $role): ?TargetedSheetEvidence;
}
