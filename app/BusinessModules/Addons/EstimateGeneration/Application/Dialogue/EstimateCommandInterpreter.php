<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface EstimateCommandInterpreter
{
    public function interpret(EstimateGenerationSession $session, int $actorId, string $command): EstimateCommandInterpretation;
}
