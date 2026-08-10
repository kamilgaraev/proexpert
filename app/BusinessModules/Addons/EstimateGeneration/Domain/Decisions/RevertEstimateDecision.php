<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions;

final readonly class RevertEstimateDecision
{
    public function __construct(private EstimateDecisionRepository $decisions) {}

    public function handle(
        string $sessionId,
        string $decisionKey,
        int $expectedVersion,
        string $reason,
        ActorContext $actor,
    ): EstimateDecision {
        $latest = $this->decisions->latest($sessionId, $decisionKey);
        if ($latest === null || $latest->sourceCommand !== 'apply') {
            throw new EstimateDecisionUndoUnavailable('estimate_decision_undo_unavailable');
        }

        return $this->decisions->append(
            $sessionId,
            $decisionKey,
            $expectedVersion,
            $latest->after,
            $latest->before,
            $reason,
            $actor,
            'revert',
            $latest->id,
        );
    }
}
