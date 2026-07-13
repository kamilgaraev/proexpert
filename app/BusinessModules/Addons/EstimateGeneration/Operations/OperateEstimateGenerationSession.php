<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use Throwable;

final readonly class OperateEstimateGenerationSession
{
    private const ACTIVE_CANCELLABLE_STATUSES = [
        'draft', 'processing_documents', 'input_review_required', 'ready_to_generate',
        'generating', 'estimate_review_required', 'ready_to_apply',
    ];

    private const ARCHIVABLE_STATUSES = ['failed', 'cancelled', 'applied'];

    private const RETRYABLE_RESUME_STATUSES = ['processing_documents', 'generating', 'applying'];

    public function __construct(
        private AdminSessionOperationAuthorizer $authorizer,
        private AdminSessionOperationTransaction $transaction,
        private AdminSessionOperationExecutor $executor,
    ) {}

    public function handle(AdminSessionOperationCommand $command): AdminSessionOperationResult
    {
        if (! $this->authorizer->canOperate($command->actorId)) {
            return AdminSessionOperationResult::failure('estimate_generation.admin_operation_forbidden');
        }
        if (! $this->validCommand($command)) {
            return AdminSessionOperationResult::failure('estimate_generation.admin_operation_invalid');
        }

        try {
            return $this->transaction->execute(
                $command,
                function (AdminSessionOperationSnapshot $snapshot) use ($command): AdminSessionOperationResult {
                    if (! $this->stateAllows($command->operation, $snapshot)) {
                        return AdminSessionOperationResult::failure('estimate_generation.admin_operation_state_conflict');
                    }

                    return $this->executor->execute($command, $snapshot);
                },
            );
        } catch (Throwable) {
            return AdminSessionOperationResult::failure('estimate_generation.admin_operation_failed');
        }
    }

    private function stateAllows(AdminSessionOperation $operation, AdminSessionOperationSnapshot $snapshot): bool
    {
        if ($operation === AdminSessionOperation::Retry) {
            return $snapshot->status === 'failed'
                && $snapshot->hasRecoverableFailure
                && in_array($snapshot->resumeStatus, self::RETRYABLE_RESUME_STATUSES, true);
        }
        if ($operation === AdminSessionOperation::Cancel) {
            return in_array($snapshot->status, self::ACTIVE_CANCELLABLE_STATUSES, true);
        }

        return in_array($snapshot->status, self::ARCHIVABLE_STATUSES, true);
    }

    private function validCommand(AdminSessionOperationCommand $command): bool
    {
        return $command->actorId > 0
            && $command->sessionId > 0
            && $command->organizationId > 0
            && $command->projectId > 0
            && $command->expectedStateVersion >= 0
            && preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $command->idempotencyKey) === 1;
    }
}
