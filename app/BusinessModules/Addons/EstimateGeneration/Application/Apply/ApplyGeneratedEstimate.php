<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyGeneratedEstimate
{
    public function __construct(
        private GeneratedEstimateWriter $writer,
        private EstimateGenerationWorkflow $workflow,
    ) {}

    public function handle(ApplyGeneratedEstimateCommand $command): ApplyGeneratedEstimateResult
    {
        return $this->transaction(function () use ($command): ApplyGeneratedEstimateResult {
            $session = $this->loadLockedSession(
                $command->sessionId,
                $command->organizationId,
                $command->projectId,
            );

            if ($session->applied_estimate_id !== null) {
                if (! $this->replayMatches((int) $session->applied_estimate_id, $command)) {
                    throw ValidationException::withMessages([
                        'draft' => [trans_message('estimate_generation.stage6_artifact_invalid')],
                    ]);
                }

                return new ApplyGeneratedEstimateResult((int) $session->applied_estimate_id, false);
            }

            if ($session->state_version !== $command->expectedStateVersion) {
                throw new StaleEstimateGenerationState((int) $session->getKey(), $command->expectedStateVersion);
            }

            if ($session->status !== EstimateGenerationStatus::ReadyToApply) {
                throw new InvalidEstimateGenerationTransition(
                    $session->status,
                    EstimateGenerationEvent::ApplyStarted,
                );
            }

            $this->workflow->transition($session, EstimateGenerationEvent::ApplyStarted);
            $estimateId = $this->writer->createFromSession($session, $command);
            $this->workflow->transition(
                $session,
                EstimateGenerationEvent::ApplyCompleted,
                [
                    'applied_estimate_id' => $estimateId,
                    'applied_at' => now(),
                    'processing_stage' => EstimateGenerationStatus::Applied->value,
                    'processing_progress' => 100,
                ],
            );

            return new ApplyGeneratedEstimateResult($estimateId, true);
        });
    }

    protected function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    protected function replayMatches(int $estimateId, ApplyGeneratedEstimateCommand $command): bool
    {
        if ($command->artifactHash === null && $command->idempotencyKey === null) {
            return true;
        }
        $metadata = $this->writer->publishedMetadata(
            $estimateId,
            $command->organizationId,
            $command->projectId,
        );
        if ($metadata === null) {
            return false;
        }

        return $this->replayMetadataMatches($metadata, $command);
    }

    protected function replayMetadataMatches(array $metadata, ApplyGeneratedEstimateCommand $command): bool
    {
        if ($command->artifactHash !== null
            && ! hash_equals($command->artifactHash, (string) (
                $metadata['generation_source_artifact_hash']
                ?? $metadata['generation_artifact_hash']
                ?? ''
            ))) {
            return false;
        }

        return $command->artifactHash !== null
            || $command->idempotencyKey === null
            || ($metadata['generation_idempotency_key'] ?? null) === $command->idempotencyKey;
    }

    protected function loadLockedSession(
        int $sessionId,
        int $organizationId,
        int $projectId,
    ): EstimateGenerationSession {
        return EstimateGenerationSession::query()
            ->whereKey($sessionId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
