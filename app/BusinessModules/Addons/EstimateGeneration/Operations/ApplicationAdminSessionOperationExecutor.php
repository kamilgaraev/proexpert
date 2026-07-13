<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class ApplicationAdminSessionOperationExecutor implements AdminSessionOperationExecutor
{
    public function __construct(
        private RetryEstimateGenerationSession $retry,
        private TransitionEstimateGenerationSession $transition,
    ) {}

    public function execute(
        AdminSessionOperationCommand $command,
        AdminSessionOperationSnapshot $snapshot,
    ): AdminSessionOperationResult {
        $session = match ($command->operation) {
            AdminSessionOperation::Retry => $this->retry->handle(new RetryEstimateGenerationSessionCommand(
                $command->sessionId,
                $command->organizationId,
                $command->projectId,
                $command->expectedStateVersion,
            )),
            AdminSessionOperation::Cancel => $this->transition($command, EstimateGenerationEvent::Cancelled),
            AdminSessionOperation::Archive => $this->transition($command, EstimateGenerationEvent::Archived),
        };

        return AdminSessionOperationResult::success(
            match ($command->operation) {
                AdminSessionOperation::Retry => 'estimate_generation.session_retried',
                AdminSessionOperation::Cancel => 'estimate_generation.session_cancelled',
                AdminSessionOperation::Archive => 'estimate_generation.session_archived',
            },
            $this->enumValue($session->status),
            (int) $session->state_version,
        );
    }

    private function transition(
        AdminSessionOperationCommand $command,
        EstimateGenerationEvent $event,
    ): EstimateGenerationSession {
        $session = EstimateGenerationSession::query()
            ->select(['id', 'organization_id', 'project_id', 'status', 'resume_status', 'state_version'])
            ->whereKey($command->sessionId)
            ->where('organization_id', $command->organizationId)
            ->where('project_id', $command->projectId)
            ->firstOrFail();

        return $this->transition->handle($session, $command->expectedStateVersion, $event);
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
