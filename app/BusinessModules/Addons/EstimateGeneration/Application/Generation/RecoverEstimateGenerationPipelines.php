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

final class RecoverEstimateGenerationPipelines
{
    private const BATCH_SIZE = 100;

    public function handle(): int
    {
        $sessions = EstimateGenerationSession::query()
            ->where('status', EstimateGenerationStatus::Generating->value)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();
        if ($sessions->isEmpty()) {
            return 0;
        }
        $completed = EstimateGenerationPipelineCheckpoint::query()
            ->whereIn('session_id', $sessions->modelKeys())
            ->where('status', CheckpointStatus::Completed->value)
            ->get(['session_id', 'stage', 'input_version'])
            ->groupBy('session_id');
        $dispatched = 0;

        foreach ($sessions as $session) {
            $attempt = (string) ($session->input_payload['generation_attempt_id'] ?? '');
            if ($attempt === '') {
                continue;
            }
            $done = ($completed->get($session->getKey()) ?? collect())
                ->filter(static fn ($row): bool => hash_equals($attempt, (string) $row->input_version))
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
                $next,
            )->onQueue(GenerateEstimateDraftJob::QUEUE)->afterCommit();
            $dispatched++;
        }

        return $dispatched;
    }
}
