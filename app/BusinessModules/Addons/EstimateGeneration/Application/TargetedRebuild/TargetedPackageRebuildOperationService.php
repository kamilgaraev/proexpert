<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Closure;
use Illuminate\Support\Str;

final readonly class TargetedPackageRebuildOperationService
{
    public function __construct(
        private TargetedPackageRebuildOperationStore $operations,
        private TargetedPackageRebuildOperationFactory $factory,
        private TargetedPackageRebuildJobScheduler $jobs,
        private bool $activeTargetedRebuildEnabled,
        private ?Closure $operationIds = null,
    ) {}

    public function scheduleAfterPublishedDraft(EstimateGenerationSession $session, array $draft): ?TargetedPackageRebuildOperationData
    {
        $status = $session->status;
        if (! $status instanceof EstimateGenerationStatus) {
            return null;
        }

        $operation = $this->factory->fromPublishedDraft(
            operationId: $this->operationId(),
            organizationId: (int) $session->organization_id,
            projectId: (int) $session->project_id,
            sessionId: (int) $session->getKey(),
            stateVersion: (int) $session->state_version,
            sessionStatus: $status->value,
            draft: $draft,
            active: $this->activeTargetedRebuildEnabled,
        );
        if (! $operation instanceof TargetedPackageRebuildOperationData) {
            return null;
        }

        $stored = $this->operations->createOrFind($operation);
        if ($stored->created) {
            $this->jobs->schedule($stored->operation->operationId);
        }

        return $stored->operation;
    }

    private function operationId(): string
    {
        if ($this->operationIds instanceof Closure) {
            return ($this->operationIds)();
        }

        return (string) Str::uuid();
    }
}
