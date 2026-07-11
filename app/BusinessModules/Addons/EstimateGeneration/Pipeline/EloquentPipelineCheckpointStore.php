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

final readonly class EloquentPipelineCheckpointStore implements PipelineCheckpointStore
{
    public function __construct(private Connection $database) {}

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
                || ($stage === ProcessingStage::BuildDraft
                    && ! hash_equals($context->inputVersion, (string) ($session->input_payload['generation_attempt_id'] ?? '')))) {
                throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
            }
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
                'stage' => $stage->value,
                'input_version' => $context->inputVersion,
            ];

            $inserted = $this->database->table('estimate_generation_pipeline_checkpoints')->insertOrIgnore([
                ...$identity,
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
        if ($claim->status !== CheckpointClaimStatus::Acquired || $result->stage !== $claim->stage) {
            return false;
        }

        PipelineVersionValidator::assertValid($result->outputVersion, 'output');

        return $this->query()
            ->where($this->identity($claim))
            ->where('status', CheckpointStatus::Running->value)
            ->where('claim_token', $claim->claimToken)
            ->where('lease_expires_at', '>', $completedAt)
            ->update([
                'status' => CheckpointStatus::Completed->value,
                'output_version' => $result->outputVersion,
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

    /** @return array{session_id: int, stage: string, input_version: string} */
    private function identity(CheckpointClaim $claim): array
    {
        return [
            'session_id' => $claim->context->sessionId,
            'stage' => $claim->stage->value,
            'input_version' => $claim->context->inputVersion,
        ];
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
