<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateEstimateDraftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [60, 180];

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $sessionId,
        private readonly int $expectedStateVersion,
        private readonly string $attemptId,
        private readonly FailureExecutionSnapshot $failureSnapshot,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('estimate-generation:draft:session:'.$this->sessionId))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 300),
            (new WithoutOverlapping('estimate-generation:draft:'.$this->rateLimitKey()))
                ->shared()
                ->releaseAfter(120)
                ->expireAfter($this->timeout + 300),
            new RateLimited('estimate-generation-drafts'),
        ];
    }

    public function rateLimitKey(): string
    {
        $organizationId = EstimateGenerationSession::query()
            ->whereKey($this->sessionId)
            ->value('organization_id');

        return $organizationId !== null
            ? 'organization:'.(int) $organizationId
            : 'session:'.$this->sessionId;
    }

    public function handle(
        DraftPipelineEntrypoint $pipeline,
    ): void {
        $current = EstimateGenerationSession::query()->find($this->sessionId);
        if (! $current instanceof EstimateGenerationSession
            || (int) $current->state_version !== $this->expectedStateVersion
            || $current->status->value !== $this->failureSnapshot->status
            || ! hash_equals($this->attemptId, (string) ($current->input_payload['generation_attempt_id'] ?? ''))) {
            return;
        }

        $run = $pipeline->run($this->failureSnapshot);
        if ($run->dispatchNext) {
            self::dispatch(
                $this->sessionId,
                $this->expectedStateVersion,
                $this->attemptId,
                $this->failureSnapshot->nextEvent(),
            )->onQueue(self::QUEUE)->afterCommit();

            return;
        }
        if (! $run->finalized || ! $run->session instanceof EstimateGenerationSession) {
            return;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $snapshot = $this->failureSnapshot;
        $checkpoint = EstimateGenerationPipelineCheckpoint::query()
            ->where('session_id', $snapshot->sessionId)
            ->where('organization_id', $snapshot->organizationId)
            ->where('project_id', $snapshot->projectId)
            ->where('generation_attempt_id', $snapshot->attemptId)
            ->where('status', 'running')
            ->whereNotNull('claim_token')
            ->orderByDesc('id')
            ->first();
        if ($checkpoint instanceof EstimateGenerationPipelineCheckpoint) {
            try {
                $failure = app(FailureRecorder::class)->capture($exception, new FailureContext(
                    organizationId: $snapshot->organizationId,
                    projectId: $snapshot->projectId,
                    sessionId: $snapshot->sessionId,
                    stage: $checkpoint->stage,
                    operation: 'run_stage',
                    attempt: (int) $checkpoint->attempt_count,
                    correlationId: AiOperationContext::deterministicId(sprintf(
                        'pipeline|%d|%s|%s',
                        $snapshot->sessionId,
                        $checkpoint->stage->value,
                        (string) $checkpoint->input_version,
                    )),
                    eventId: (string) $checkpoint->claim_token,
                    expectedSessionStateVersion: $snapshot->stateVersion,
                    expectedSessionStatus: $snapshot->status,
                    checkpointId: (int) $checkpoint->getKey(),
                ));
                $definition = PipelineDefinitionGraph::standard()->get($checkpoint->stage);
                $storedDependencies = is_array($checkpoint->dependency_versions) ? $checkpoint->dependency_versions : [];
                $dependencies = [];
                foreach ($definition->dependencies as $dependency) {
                    $dependencies[$dependency->value] = (string) ($storedDependencies[$dependency->value] ?? '');
                }
                $context = new PipelineContext(
                    sessionId: $snapshot->sessionId,
                    organizationId: $snapshot->organizationId,
                    projectId: $snapshot->projectId,
                    stateVersion: $snapshot->stateVersion,
                    inputVersion: (string) $checkpoint->input_version,
                    sessionStatus: $snapshot->status,
                    generationAttemptId: $snapshot->attemptId,
                    baseInputVersion: (string) $checkpoint->base_input_version,
                    stage: $checkpoint->stage,
                    dependencyVersions: $dependencies,
                );
                $claim = CheckpointClaim::acquired(
                    $context,
                    $checkpoint->stage,
                    (string) $checkpoint->claim_token,
                    (int) $checkpoint->attempt_count,
                    (int) $checkpoint->getKey(),
                );
                if (app(PipelineCheckpointStore::class)->fail($claim, $exception, new \DateTimeImmutable)) {
                    app(FailureWorkflowHandler::class)->handle($failure, $snapshot->stateVersion);
                }
            } catch (\Throwable) {
            }
        }

        Log::error('[EstimateGeneration] Draft generation job failed', [
            'session_id' => $this->sessionId,
            'failure_code' => 'draft_generation_failed',
            'failure_fingerprint' => hash('sha256', $exception::class.'|'.(string) $exception->getCode()),
        ]);
    }
}
