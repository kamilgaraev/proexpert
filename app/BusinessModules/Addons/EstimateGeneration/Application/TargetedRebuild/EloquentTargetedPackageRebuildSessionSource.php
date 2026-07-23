<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EloquentTargetedPackageRebuildSessionSource implements TargetedPackageRebuildSessionSource
{
    public function find(TargetedPackageRebuildOperationData $operation): ?TargetedPackageRebuildSessionSnapshot
    {
        $session = EstimateGenerationSession::query()
            ->whereKey($operation->sessionId)
            ->where('organization_id', $operation->organizationId)
            ->where('project_id', $operation->projectId)
            ->first();
        if (! $session instanceof EstimateGenerationSession || ! $session->status instanceof EstimateGenerationStatus) {
            return null;
        }

        return new TargetedPackageRebuildSessionSnapshot(
            organizationId: (int) $session->organization_id,
            projectId: (int) $session->project_id,
            sessionId: (int) $session->getKey(),
            stateVersion: (int) $session->state_version,
            status: $session->status->value,
            appliedEstimateId: is_int($session->applied_estimate_id) ? $session->applied_estimate_id : null,
            draft: is_array($session->draft_payload) ? $session->draft_payload : [],
        );
    }
}
