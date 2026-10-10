<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;

interface EstimateGenerationActionAuthorization
{
    public function authorize(User|SystemAdmin $actor, EstimateGenerationSession $session, string $permission): void;
}
