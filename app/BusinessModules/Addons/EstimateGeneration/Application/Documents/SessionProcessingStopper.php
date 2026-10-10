<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;

interface SessionProcessingStopper
{
    public function haltForSession(EstimateGenerationSession $session, User|SystemAdmin $actor): void;
}
