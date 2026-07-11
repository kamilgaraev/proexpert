<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EloquentSessionStateStore implements SessionStateStore
{
    public function compareAndSet(
        int $sessionId,
        int $expectedVersion,
        EstimateGenerationStatus $status,
        array $attributes,
    ): void {
        $updated = EstimateGenerationSession::query()
            ->whereKey($sessionId)
            ->where('state_version', $expectedVersion)
            ->update([
                ...$attributes,
                'status' => $status->value,
                'state_version' => $expectedVersion + 1,
            ]);

        if ($updated !== 1) {
            throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
        }
    }
}
