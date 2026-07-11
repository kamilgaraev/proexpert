<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class EloquentPipelineCheckpointStore implements PipelineCheckpointStore
{
    public function __construct(private ConnectionInterface $database) {}

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
            $checkpoint = EstimateGenerationPipelineCheckpoint::query()
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

        return EstimateGenerationPipelineCheckpoint::query()
            ->where($this->identity($claim))
            ->where('status', CheckpointStatus::Running->value)
            ->where('claim_token', $claim->claimToken)
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
                'updated_at' => $completedAt,
            ]) === 1;
    }

    public function fail(CheckpointClaim $claim, Throwable $error, DateTimeImmutable $failedAt): bool
    {
        if ($claim->status !== CheckpointClaimStatus::Acquired) {
            return false;
        }

        return EstimateGenerationPipelineCheckpoint::query()
            ->where($this->identity($claim))
            ->where('status', CheckpointStatus::Running->value)
            ->where('claim_token', $claim->claimToken)
            ->update([
                'status' => CheckpointStatus::Failed->value,
                'claim_token' => null,
                'lease_expires_at' => null,
                'failed_at' => $failedAt,
                'last_error_code' => mb_substr($error::class, 0, 160),
                'last_error_message' => $this->safeErrorMessage($error),
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

    private function safeErrorMessage(Throwable $error): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error->getMessage()) ?? '';

        return mb_substr(trim($message), 0, 1000);
    }
}
