<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions;

final readonly class ApplyEstimateDecision
{
    public function __construct(private EstimateDecisionRepository $decisions) {}

    public function handle(
        string $sessionId,
        string $decisionKey,
        int $expectedVersion,
        array $before,
        array $after,
        string $reason,
        ActorContext $actor,
    ): EstimateDecision {
        return $this->decisions->append(
            $sessionId,
            $decisionKey,
            $expectedVersion,
            $before,
            $after,
            $reason,
            $actor,
            'apply',
        );
    }
}
