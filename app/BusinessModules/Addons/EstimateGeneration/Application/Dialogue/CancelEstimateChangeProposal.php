<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class CancelEstimateChangeProposal
{
    public function __construct(private EstimateChangeProposalRepository $proposals) {}

    public function handle(int $actorId, int $organizationId, int $projectId, int $sessionId, string $proposalId): EstimateChangeProposal
    {
        return DB::transaction(function () use ($actorId, $organizationId, $projectId, $sessionId, $proposalId): EstimateChangeProposal {
            $proposal = $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId, true);
            if ($proposal->payload['status'] === 'cancelled') {
                return $proposal;
            }
            if ($proposal->payload['status'] !== 'proposed' || ! $this->proposals->transition($proposalId, 'proposed', 'cancelled', $actorId)) {
                throw new RuntimeException('estimate_generation.proposal_terminal');
            }

            return $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId);
        }, 3);
    }
}
