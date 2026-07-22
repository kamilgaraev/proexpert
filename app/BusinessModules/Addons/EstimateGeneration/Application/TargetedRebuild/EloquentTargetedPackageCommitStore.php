<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Closure;
use Illuminate\Support\Facades\DB;

final class EloquentTargetedPackageCommitStore implements TargetedPackageCommitStore
{
    public function withinLockedSession(int $sessionId, int $organizationId, int $projectId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($sessionId, $organizationId, $projectId, $callback): mixed {
            $session = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->first();
            if (! $session instanceof EstimateGenerationSession) {
                throw new StaleEstimateGenerationState($sessionId, 0);
            }

            return $callback($session);
        });
    }

    public function operation(EstimateGenerationSession $session, string $operationId): ?array
    {
        $event = EstimateGenerationAuditEvent::query()
            ->where('session_id', $session->getKey())
            ->where('event_type', 'targeted_package_rebuild_committed')
            ->where('payload->operation_id', $operationId)
            ->first();

        return $event instanceof EstimateGenerationAuditEvent && is_array($event->payload)
            ? $event->payload
            : null;
    }

    public function recordOperation(EstimateGenerationSession $session, array $payload): void
    {
        EstimateGenerationAuditEvent::query()->create([
            'session_id' => $session->getKey(),
            'package_id' => null,
            'user_id' => null,
            'event_type' => 'targeted_package_rebuild_committed',
            'payload' => $payload,
        ]);
    }
}
