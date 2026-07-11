<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Stage execution must finish within the configured lease. Long-running stage
 * orchestration must renew the acquired claim before expiry; expired work is
 * never published because completion is guarded by the store lease CAS.
 */
final class PipelineRunner
{
    public const DEFAULT_LEASE_SECONDS = 300;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    private readonly PipelineFailureObserver $failureObserver;

    /** @param callable(): DateTimeImmutable $clock */
    public function __construct(
        private readonly PipelineRegistry $registry,
        private readonly PipelineCheckpointStore $checkpointStore,
        private readonly FailureRecorder $failureRecorder,
        private readonly FailureWorkflowHandler $failureWorkflowHandler,
        callable $clock,
        private readonly int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        ?PipelineFailureObserver $failureObserver = null,
    ) {
        if ($leaseSeconds <= 0) {
            throw new InvalidArgumentException('Pipeline checkpoint lease must be positive.');
        }

        $this->clock = Closure::fromCallable($clock);
        $this->failureObserver = $failureObserver ?? new NullPipelineFailureObserver;
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
            $result = $stage instanceof LeaseAwarePipelineStage
                ? $stage->executeWithHeartbeat($context, $this->heartbeat($claim))
                : $stage->execute($context);

            if ($result->stage !== $stage->stage()) {
                throw new PipelineStageException(FailureCategory::Terminal, 'pipeline_result_stage_mismatch');
            }

            if (! $this->checkpointStore->complete($claim, $result, ($this->clock)())) {
                throw new PipelineStageException(FailureCategory::Recoverable, 'pipeline_claim_lost');
            }

            try {
                $this->failureRecorder->resolveActive($this->failureContext($claim));
            } catch (Throwable) {
            }

            return $result;
        } catch (Throwable $error) {
            $failure = $this->failureRecorder->capture($error, $this->failureContext($claim));
            $recorded = $this->recordFailureWithoutMasking($claim, $error);
            if ($recorded) {
                try {
                    $this->failureWorkflowHandler->handle($failure, $claim->context->stateVersion);
                } catch (Throwable) {
                }
            }

            throw $error;
        }
    }

    private function heartbeat(CheckpointClaim $claim): PipelineLeaseHeartbeat
    {
        return new PipelineLeaseHeartbeat(function () use ($claim): bool {
            $now = ($this->clock)();

            return $this->checkpointStore->renewLease(
                $claim,
                $now,
                $now->modify(sprintf('+%d seconds', $this->leaseSeconds)),
            );
        });
    }

    private function recordFailureWithoutMasking(CheckpointClaim $claim, Throwable $stageError): bool
    {
        $recorderFailure = null;

        try {
            $recorded = $this->checkpointStore->fail($claim, $stageError, ($this->clock)());
        } catch (Throwable $error) {
            $recorded = false;
            $recorderFailure = PipelineFailureDetails::from($error);
        }

        if ($recorded) {
            return true;
        }

        try {
            $this->failureObserver->checkpointFailureWasNotRecorded(
                $claim,
                PipelineFailureDetails::from($stageError),
                $recorderFailure,
            );
        } catch (Throwable) {
        }

        return false;
    }

    private function failureContext(CheckpointClaim $claim): FailureContext
    {
        return new FailureContext(
            organizationId: $claim->context->organizationId,
            projectId: $claim->context->projectId,
            sessionId: $claim->context->sessionId,
            stage: $claim->stage,
            operation: 'run_stage',
            attempt: $claim->attempt,
            correlationId: AiOperationContext::deterministicId(sprintf(
                'pipeline|%d|%s|%s',
                $claim->context->sessionId,
                $claim->stage->value,
                $claim->context->inputVersion,
            )),
            eventId: (string) $claim->claimToken,
            expectedSessionStateVersion: $claim->context->stateVersion,
            expectedSessionStatus: $claim->context->sessionStatus,
            checkpointId: $claim->checkpointId,
        );
    }
}
