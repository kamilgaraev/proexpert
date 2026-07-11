<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;
use Throwable;

final class InMemoryPipelineStateStore implements PipelineCheckpointStore, PipelineOutputRepository
{
    private array $records = [];

    private int $sequence = 0;

    public function __construct(private readonly PipelineArtifactStore $artifacts) {}

    public function claim(PipelineContext $context, ProcessingStage $stage, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): CheckpointClaim
    {
        $key = $this->key($context, $stage);
        $record = $this->records[$key] ?? null;
        if (($record['status'] ?? null) === CheckpointStatus::Completed) {
            return CheckpointClaim::alreadyCompleted($context, $stage);
        }
        if (($record['status'] ?? null) === CheckpointStatus::Running && $record['lease'] > $now) {
            return CheckpointClaim::busy($context, $stage);
        }
        $attempt = (int) ($record['attempt'] ?? 0) + 1;
        $token = sprintf('00000000-0000-4000-8000-%012d', ++$this->sequence);
        $this->records[$key] = ['status' => CheckpointStatus::Running, 'lease' => $leaseExpiresAt, 'token' => $token, 'attempt' => $attempt, 'output' => null];

        return CheckpointClaim::acquired($context, $stage, $token, $attempt, $this->sequence);
    }

    public function complete(CheckpointClaim $claim, PipelineStageResult $result, DateTimeImmutable $completedAt): bool
    {
        $key = $this->key($claim->context, $claim->stage);
        $record = $this->records[$key] ?? null;
        if (($record['status'] ?? null) !== CheckpointStatus::Running || ($record['token'] ?? null) !== $claim->claimToken || $record['lease'] <= $completedAt) {
            return false;
        }
        $this->records[$key] = [...$record, 'status' => CheckpointStatus::Completed, 'output' => $result->output];

        return true;
    }

    public function renewLease(CheckpointClaim $claim, DateTimeImmutable $now, DateTimeImmutable $newLeaseExpiresAt): bool
    {
        $key = $this->key($claim->context, $claim->stage);
        if (($this->records[$key]['token'] ?? null) !== $claim->claimToken || $this->records[$key]['lease'] <= $now) {
            return false;
        }
        $this->records[$key]['lease'] = $newLeaseExpiresAt;

        return true;
    }

    public function fail(CheckpointClaim $claim, Throwable $error, DateTimeImmutable $failedAt): bool
    {
        $key = $this->key($claim->context, $claim->stage);
        if (($this->records[$key]['token'] ?? null) !== $claim->claimToken) {
            return false;
        }
        $this->records[$key]['status'] = CheckpointStatus::Failed;
        $this->records[$key]['lease'] = $failedAt;

        return true;
    }

    public function priorOutputs(PipelineContext $context): PipelinePriorOutputs
    {
        $outputs = [];
        foreach (ProcessingStage::cases() as $stage) {
            $output = $this->records[$this->key($context, $stage)]['output'] ?? null;
            if ($output instanceof PipelineStageOutput) {
                $outputs[$stage->value] = $output;
            }
        }

        return new PipelinePriorOutputs($outputs, loader: fn (PipelineStageOutput $output): array => $this->artifacts->read($context, $output));
    }

    public function completedCount(): int
    {
        return count(array_filter($this->records, static fn (array $record): bool => $record['status'] === CheckpointStatus::Completed));
    }

    private function key(PipelineContext $context, ProcessingStage $stage): string
    {
        return $context->sessionId.'|'.$context->inputVersion.'|'.$stage->value;
    }
}
