<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EloquentPipelineCheckpointStore implements PipelineCheckpointStore
{
    private readonly PipelineCompletionHook $completionHook;

    public function __construct(private readonly Connection $database, ?PipelineCompletionHook $completionHook = null)
    {
        $this->completionHook = $completionHook ?? new NullPipelineCompletionHook;
    }

    public function claim(
        PipelineContext $context,
        ProcessingStage $stage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): CheckpointClaim {
        if ($leaseExpiresAt <= $now) {
            throw new InvalidArgumentException('Checkpoint lease expiration must be later than claim time.');
        }

        return $this->database->transaction(function () use ($context, $stage, $now, $leaseExpiresAt): CheckpointClaim {
            $session = $this->sessionQuery()
                ->whereKey($context->sessionId)
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->lockForUpdate()
                ->first();
            if (! $session instanceof EstimateGenerationSession
                || (int) $session->state_version !== $context->stateVersion
                || $session->status->value !== $context->sessionStatus
                || $context->stage !== $stage
                || $context->generationAttemptId === null
                || ($context->documentId === null
                    && ! hash_equals($context->generationAttemptId, (string) ($session->input_payload['generation_attempt_id'] ?? '')))) {
                throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
            }
            $this->assertDependenciesCurrent($context);
            if ($context->documentId !== null) {
                $document = $this->documentQuery()
                    ->whereKey($context->documentId)
                    ->where('organization_id', $context->organizationId)
                    ->where('project_id', $context->projectId)
                    ->where('session_id', $context->sessionId)
                    ->lockForUpdate()
                    ->first();
                if (! $document instanceof EstimateGenerationDocument
                    || DocumentSourceVersion::fromDocument($document) !== $context->sourceVersion) {
                    throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
                }
            }
            $token = (string) Str::uuid();
            $identity = [
                'session_id' => $context->sessionId,
                'generation_attempt_id' => $context->generationAttemptId,
                'stage' => $stage->value,
                'input_version' => $context->inputVersion,
            ];

            $inserted = $this->database->table('estimate_generation_pipeline_checkpoints')->insertOrIgnore([
                ...$identity,
                'organization_id' => $context->organizationId,
                'project_id' => $context->projectId,
                'base_input_version' => $context->baseInputVersion,
                'dependency_versions' => json_encode($context->dependencyVersions, JSON_THROW_ON_ERROR),
                'status' => CheckpointStatus::Running->value,
                'metrics' => '{}',
                'warnings' => '[]',
                'attempt_count' => 1,
                'claim_token' => $token,
                'lease_expires_at' => $leaseExpiresAt,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                $checkpoint = $this->query()->where($identity)->firstOrFail();

                return CheckpointClaim::acquired($context, $stage, $token, 1, (int) $checkpoint->getKey());
            }

            /** @var EstimateGenerationPipelineCheckpoint|null $checkpoint */
            $checkpoint = $this->query()
                ->where($identity)
                ->lockForUpdate()
                ->first();

            if ($checkpoint === null) {
                throw new RuntimeException('Checkpoint disappeared while acquiring its lease.');
            }

            if ($checkpoint->status === CheckpointStatus::Completed) {
                return CheckpointClaim::alreadyCompleted($context, $stage);
            }

            if (
                $checkpoint->status === CheckpointStatus::Running
                && $checkpoint->lease_expires_at !== null
                && $checkpoint->lease_expires_at->toDateTimeImmutable() > $now
            ) {
                return CheckpointClaim::busy($context, $stage);
            }

            $checkpoint->forceFill([
                'status' => CheckpointStatus::Running,
                'output_version' => null,
                'output_payload' => null,
                'metrics' => [],
                'warnings' => [],
                'attempt_count' => $checkpoint->attempt_count + 1,
                'claim_token' => $token,
                'lease_expires_at' => $leaseExpiresAt,
                'started_at' => $now,
                'completed_at' => null,
                'failed_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_fingerprint' => null,
            ])->save();

            return CheckpointClaim::acquired(
                $context,
                $stage,
                $token,
                (int) $checkpoint->attempt_count,
                (int) $checkpoint->getKey(),
            );
        }, 3);
    }

    public function complete(
        CheckpointClaim $claim,
        PipelineStageResult $result,
        DateTimeImmutable $completedAt,
    ): bool {
        if ($claim->status !== CheckpointClaimStatus::Acquired || $result->stage !== $claim->stage || $result->output === null) {
            return false;
        }

        PipelineVersionValidator::assertValid($result->outputVersion, 'output');

        return $this->database->transaction(function () use ($claim, $result, $completedAt): bool {
            $session = $this->sessionQuery()
                ->whereKey($claim->context->sessionId)
                ->where('organization_id', $claim->context->organizationId)
                ->where('project_id', $claim->context->projectId)
                ->lockForUpdate()
                ->first();
            if (! $session instanceof EstimateGenerationSession
                || (int) $session->state_version !== $claim->context->stateVersion
                || $session->status->value !== $claim->context->sessionStatus
                || $claim->context->generationAttemptId === null
                || ! hash_equals($claim->context->generationAttemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
                return false;
            }
            $checkpoint = $this->query()
                ->where($this->identity($claim))
                ->where('status', CheckpointStatus::Running->value)
                ->where('claim_token', $claim->claimToken)
                ->where('lease_expires_at', '>', $completedAt)
                ->lockForUpdate()
                ->first();
            if (! $checkpoint instanceof EstimateGenerationPipelineCheckpoint) {
                return false;
            }

            $this->completionHook->beforeComplete($claim, $result, $completedAt);
            $artifactBytes = $result->output->artifact->bytes;
            $aggregateBytes = (int) $this->query()
                ->where('session_id', $claim->context->sessionId)
                ->where('organization_id', $claim->context->organizationId)
                ->where('project_id', $claim->context->projectId)
                ->where('generation_attempt_id', $claim->context->generationAttemptId)
                ->where('status', CheckpointStatus::Completed->value)
                ->sum('artifact_bytes');
            if ($aggregateBytes + $artifactBytes > PipelineDefinitionGraph::MAX_TOTAL_ARTIFACT_BYTES) {
                throw new RuntimeException('estimate_generation.pipeline_artifact_budget_exceeded');
            }

            return $checkpoint->newQuery()
                ->whereKey($checkpoint->getKey())
                ->where('status', CheckpointStatus::Running->value)
                ->where('claim_token', $claim->claimToken)
                ->update([
                    'status' => CheckpointStatus::Completed->value,
                    'output_version' => $result->outputVersion,
                    'output_payload' => json_encode($result->output->envelope(), JSON_THROW_ON_ERROR),
                    'artifact_bytes' => $artifactBytes,
                    'metrics' => json_encode($result->metrics, JSON_THROW_ON_ERROR),
                    'warnings' => json_encode($result->warnings, JSON_THROW_ON_ERROR),
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'completed_at' => $completedAt,
                    'failed_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'last_error_fingerprint' => null,
                    'updated_at' => $completedAt,
                ]) === 1;
        }, 3);
    }

    public function renewLease(
        CheckpointClaim $claim,
        DateTimeImmutable $now,
        DateTimeImmutable $newLeaseExpiresAt,
    ): bool {
        if ($claim->status !== CheckpointClaimStatus::Acquired || $newLeaseExpiresAt <= $now) {
            return false;
        }

        return $this->query()
            ->where($this->identity($claim))
            ->where('status', CheckpointStatus::Running->value)
            ->where('claim_token', $claim->claimToken)
            ->where('lease_expires_at', '>', $now)
            ->update([
                'lease_expires_at' => $newLeaseExpiresAt,
                'updated_at' => $now,
            ]) === 1;
    }

    public function fail(CheckpointClaim $claim, Throwable $error, DateTimeImmutable $failedAt): bool
    {
        if ($claim->status !== CheckpointClaimStatus::Acquired) {
            return false;
        }

        $failure = PipelineFailureDetails::from($error);

        return $this->query()
            ->where($this->identity($claim))
            ->where('status', CheckpointStatus::Running->value)
            ->where('claim_token', $claim->claimToken)
            ->where('lease_expires_at', '>', $failedAt)
            ->update([
                'status' => CheckpointStatus::Failed->value,
                'claim_token' => null,
                'lease_expires_at' => null,
                'failed_at' => $failedAt,
                'last_error_code' => $failure->code,
                'last_error_message' => null,
                'last_error_fingerprint' => $failure->fingerprint,
                'updated_at' => $failedAt,
            ]) === 1;
    }

    public function invalidateDownstream(PipelineContext $context, ProcessingStage $changedStage, DateTimeImmutable $invalidatedAt): int
    {
        if ($context->generationAttemptId === null) {
            return 0;
        }
        $downstream = array_map(
            static fn (ProcessingStage $stage): string => $stage->value,
            array_filter(ProcessingStage::cases(), static fn (ProcessingStage $stage): bool => $stage->order() >= $changedStage->order()),
        );

        return $this->query()
            ->where('session_id', $context->sessionId)
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->where('generation_attempt_id', $context->generationAttemptId)
            ->whereIn('stage', $downstream)
            ->where('status', CheckpointStatus::Completed->value)
            ->update([
                'status' => CheckpointStatus::Invalidated->value,
                'invalidated_at' => $invalidatedAt,
                'invalidation_reason' => 'dependency_changed',
                'updated_at' => $invalidatedAt,
            ]);
    }

    /** @return array{session_id: int, generation_attempt_id: string|null, stage: string, input_version: string} */
    private function identity(CheckpointClaim $claim): array
    {
        return [
            'session_id' => $claim->context->sessionId,
            'generation_attempt_id' => $claim->context->generationAttemptId,
            'stage' => $claim->stage->value,
            'input_version' => $claim->context->inputVersion,
        ];
    }

    private function assertDependenciesCurrent(PipelineContext $context): void
    {
        if ($context->dependencyVersions === []) {
            return;
        }
        $rows = $this->query()
            ->where('session_id', $context->sessionId)
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->where('generation_attempt_id', $context->generationAttemptId)
            ->where('status', CheckpointStatus::Completed->value)
            ->whereIn('stage', array_keys($context->dependencyVersions))
            ->get(['stage', 'output_version'])
            ->mapWithKeys(static fn (EstimateGenerationPipelineCheckpoint $checkpoint): array => [$checkpoint->stage->value => (string) $checkpoint->output_version])
            ->all();
        if (count($rows) !== count($context->dependencyVersions)) {
            throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
        }
        foreach ($context->dependencyVersions as $stage => $version) {
            if (! isset($rows[$stage]) || ! hash_equals($version, $rows[$stage])) {
                throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
            }
        }
    }

    /** @return Builder<EstimateGenerationPipelineCheckpoint> */
    private function query(): Builder
    {
        $model = new EstimateGenerationPipelineCheckpoint;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    /** @return Builder<EstimateGenerationSession> */
    private function sessionQuery(): Builder
    {
        $model = new EstimateGenerationSession;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    /** @return Builder<EstimateGenerationDocument> */
    private function documentQuery(): Builder
    {
        $model = new EstimateGenerationDocument;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }
}
