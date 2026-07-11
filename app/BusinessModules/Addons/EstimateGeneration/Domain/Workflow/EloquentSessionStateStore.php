<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EloquentSessionStateStore implements SessionStateStore
{
    public function create(array $attributes): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->create([
            ...$attributes,
            'status' => EstimateGenerationStatus::Draft->value,
            'state_version' => 0,
            'resume_status' => null,
        ]);
    }

    public function compareAndSet(
        EstimateGenerationSession $session,
        int $expectedVersion,
        EstimateGenerationStatus $status,
        array $attributes,
    ): EstimateGenerationSession {
        $updated = EstimateGenerationSession::query()
            ->whereKey($session->getKey())
            ->where('state_version', $expectedVersion)
            ->update([
                ...$attributes,
                'status' => $status->value,
                'state_version' => $expectedVersion + 1,
            ]);

        if ($updated !== 1) {
            throw new StaleEstimateGenerationState((int) $session->getKey(), $expectedVersion);
        }

        $session->forceFill([
            ...$attributes,
            'status' => $status->value,
            'state_version' => $expectedVersion + 1,
        ])->syncChanges();

        return $session;
    }
}
