<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface EstimateCommandInterpreter
{
    /** @param array<string, mixed>|null $context */
    public function interpret(EstimateGenerationSession $session, int $actorId, string $command, ?array $context = null): EstimateCommandInterpretation;
}
