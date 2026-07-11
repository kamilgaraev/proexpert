<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
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
                return CheckpointClaim::acquired($context, $stage, $token);
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

            return CheckpointClaim::acquired($context, $stage, $token);
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
}
