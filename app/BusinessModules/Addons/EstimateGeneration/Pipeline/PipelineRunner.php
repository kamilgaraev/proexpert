<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class PipelineRunner
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /** @param callable(): DateTimeImmutable $clock */
    public function __construct(
        private readonly PipelineRegistry $registry,
        private readonly PipelineCheckpointStore $checkpointStore,
        callable $clock,
        private readonly int $leaseSeconds = 300,
    ) {
        if ($leaseSeconds <= 0) {
            throw new InvalidArgumentException('Pipeline checkpoint lease must be positive.');
        }

        $this->clock = Closure::fromCallable($clock);
    }

    public function runNext(PipelineContext $context): ?PipelineStageResult
    {
        foreach ($this->registry->ordered() as $stage) {
            $now = ($this->clock)();
            $claim = $this->checkpointStore->claim(
                $context,
                $stage->stage(),
                $now,
                $now->modify(sprintf('+%d seconds', $this->leaseSeconds)),
            );

            if ($claim->status === CheckpointClaimStatus::AlreadyCompleted) {
                continue;
            }

            if ($claim->status === CheckpointClaimStatus::Busy) {
                return null;
            }

            return $this->executeClaimed($stage, $context, $claim);
        }

        return null;
    }

    private function executeClaimed(
        PipelineStage $stage,
        PipelineContext $context,
        CheckpointClaim $claim,
    ): PipelineStageResult {
        try {
            $result = $stage->execute($context);

            if ($result->stage !== $stage->stage()) {
                throw new LogicException('Pipeline stage returned a result for another stage.');
            }

            if (! $this->checkpointStore->complete($claim, $result, ($this->clock)())) {
                throw new LogicException('Pipeline checkpoint ownership was lost before completion.');
            }

            return $result;
        } catch (Throwable $error) {
            $this->checkpointStore->fail($claim, $error, ($this->clock)());

            throw $error;
        }
    }
}
