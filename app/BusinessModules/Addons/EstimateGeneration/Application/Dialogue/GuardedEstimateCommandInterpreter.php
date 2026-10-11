<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;
use Closure;

interface GuardedEstimateCommandInterpreter extends EstimateCommandInterpreter
{
    public function interpretWithAttempt(EstimateGenerationSession $session, User|SystemAdmin $actor, string $command, array $context, string $requestKey, Closure $beforeSend): EstimateCommandInterpretation;

    public function recoverReceivedResponse(EstimateGenerationSession $session, User|SystemAdmin $actor, string $command, array $context, string $requestKey): ?EstimateCommandInterpretation;
}
