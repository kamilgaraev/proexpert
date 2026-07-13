<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use Throwable;

final readonly class ResolveEstimateGenerationFailure
{
    public function __construct(
        private AdminFailureResolutionAuthorizer $authorizer,
        private AdminFailureResolutionTransaction $transaction,
    ) {}

    public function handle(AdminFailureResolutionCommand $command): AdminFailureResolutionResult
    {
        if (! $this->authorizer->canOperate($command->actorId)) {
            return AdminFailureResolutionResult::failure('estimate_generation.admin_operation_forbidden');
        }
        if (! $this->valid($command)) {
            return AdminFailureResolutionResult::failure('estimate_generation.admin_operation_invalid');
        }

        try {
            return $this->transaction->execute(
                $command,
                static function (AdminFailureResolutionSnapshot $snapshot, callable $resolve) use ($command): AdminFailureResolutionResult {
                    if ($snapshot->failureId !== $command->failureId
                        || $snapshot->organizationId !== $command->organizationId
                        || $snapshot->projectId !== $command->projectId
                        || $snapshot->sessionId !== $command->sessionId
                        || $snapshot->latestOccurrenceSequence !== $command->expectedOccurrenceSequence
                        || ! $snapshot->hasActiveOccurrence) {
                        return AdminFailureResolutionResult::failure('estimate_generation.failure_resolution_state_conflict');
                    }

                    $resolve();

                    return AdminFailureResolutionResult::success();
                },
            );
        } catch (Throwable) {
            return AdminFailureResolutionResult::failure('estimate_generation.failure_resolution_failed');
        }
    }

    private function valid(AdminFailureResolutionCommand $command): bool
    {
        return $command->actorId > 0
            && preg_match('/^[0-9a-f-]{36}$/', $command->failureId) === 1
            && $command->organizationId > 0
            && $command->projectId > 0
            && $command->sessionId > 0
            && $command->expectedOccurrenceSequence > 0
            && preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $command->idempotencyKey) === 1;
    }
}
