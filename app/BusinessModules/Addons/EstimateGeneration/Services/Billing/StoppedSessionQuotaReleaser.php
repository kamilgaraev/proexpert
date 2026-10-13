<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Billing;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface StoppedSessionQuotaReleaser
{
    public function releaseCancelledReservation(EstimateGenerationSession $session): void;
}
