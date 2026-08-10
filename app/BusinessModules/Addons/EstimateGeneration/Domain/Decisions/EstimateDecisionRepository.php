<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions;

interface EstimateDecisionRepository
{
    public function append(
        string $sessionId,
        string $decisionKey,
        int $expectedVersion,
        array $before,
        array $after,
        string $reason,
        ActorContext $actor,
        string $sourceCommand,
        ?int $revertedDecisionId = null,
    ): EstimateDecision;

    public function latest(string $sessionId, string $decisionKey): ?EstimateDecision;

    /** @return list<EstimateDecision> */
    public function history(string $sessionId, string $decisionKey): array;
}
