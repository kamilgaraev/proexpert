<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final class RecoverEstimateGenerationPipelines
{
    private const BATCH_SIZE = 100;

    public function __construct(private Connection $database) {}

    public function handle(): int
    {
        $now = new DateTimeImmutable;
        $cursor = $this->cursor();
        $sessions = EstimateGenerationSession::query()
            ->where('status', EstimateGenerationStatus::Generating->value)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();
        if ($sessions->count() < self::BATCH_SIZE) {
            $wrapped = EstimateGenerationSession::query()
                ->where('status', EstimateGenerationStatus::Generating->value)
                ->where('id', '<=', $cursor)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE - $sessions->count())
                ->get();
            $sessions = $sessions->concat($wrapped);
        }
        if ($sessions->isEmpty()) {
            return 0;
        }
        $checkpoints = EstimateGenerationPipelineCheckpoint::query()
            ->whereIn('session_id', $sessions->modelKeys())
            ->whereIn('status', [CheckpointStatus::Running->value, CheckpointStatus::Completed->value, CheckpointStatus::Failed->value])
            ->get(['session_id', 'generation_attempt_id', 'stage', 'status', 'lease_expires_at'])
            ->groupBy('session_id');
        $dispatched = 0;

        foreach ($sessions as $session) {
            $attempt = (string) ($session->input_payload['generation_attempt_id'] ?? '');
            if ($attempt === '') {
                continue;
            }
            $attemptRows = ($checkpoints->get($session->getKey()) ?? collect())
                ->filter(static fn ($row): bool => hash_equals($attempt, (string) $row->generation_attempt_id));
            $hasActiveLease = $attemptRows->contains(static fn ($row): bool => $row->status === CheckpointStatus::Running
                && $row->lease_expires_at !== null
                && $row->lease_expires_at->toDateTimeImmutable() > $now);
            if ($hasActiveLease) {
                continue;
            }
            $done = $attemptRows
                ->filter(static fn ($row): bool => $row->status === CheckpointStatus::Completed)
                ->mapWithKeys(static fn ($row): array => [$row->stage->value => true])
                ->all();
            $next = collect(ProcessingStage::cases())->first(static fn (ProcessingStage $stage): bool => ! isset($done[$stage->value]));
            if (! $next instanceof ProcessingStage) {
                continue;
            }
            GenerateEstimateDraftJob::dispatch(
                (int) $session->getKey(),
                (int) $session->state_version,
                $attempt,
                FailureExecutionSnapshot::capture($session, 'recover_generation_pipeline', $attempt),
            )->onQueue(GenerateEstimateDraftJob::QUEUE)->afterCommit();
            $dispatched++;
        }

        $this->saveCursor((int) $sessions->last()->getKey());

        return $dispatched;
    }

    private function cursor(): int
    {
        $row = $this->database->table('estimate_generation_recovery_cursors')->where('consumer', 'generation_pipeline')->first();

        return $row === null ? 0 : (int) $row->last_session_id;
    }

    private function saveCursor(int $sessionId): void
    {
        $this->database->table('estimate_generation_recovery_cursors')->upsert([[
            'consumer' => 'generation_pipeline',
            'last_session_id' => $sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['consumer'], ['last_session_id', 'updated_at']);
    }
}
