<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;

interface EstimateProposalMutationExecutor
{
    /** @return array<string, mixed> */
    public function apply(User $actor, EstimateGenerationSession $session, EstimateChangeProposal $proposal): array;
}
