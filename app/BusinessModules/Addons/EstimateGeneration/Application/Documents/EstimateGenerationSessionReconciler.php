<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface EstimateGenerationSessionReconciler
{
    public function reconcile(EstimateGenerationSession $session): EstimateGenerationSession;
}
