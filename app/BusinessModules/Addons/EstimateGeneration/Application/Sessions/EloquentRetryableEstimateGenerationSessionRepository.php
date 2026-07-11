<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Facades\DB;

final class EloquentRetryableEstimateGenerationSessionRepository implements RetryableEstimateGenerationSessionRepository
{
    public function withLockedSession(
        int $sessionId,
        int $organizationId,
        int $projectId,
        callable $operation,
    ): EstimateGenerationSession {
        return DB::transaction(function () use ($sessionId, $organizationId, $projectId, $operation): EstimateGenerationSession {
            $session = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->with('documents')
                ->lockForUpdate()
                ->firstOrFail();

            return $operation($session);
        });
    }
}
