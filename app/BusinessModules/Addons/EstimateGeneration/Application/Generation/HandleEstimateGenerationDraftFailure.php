<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecoveryOutcome;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SimpleFailureRecoveryPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class HandleEstimateGenerationDraftFailure
{
    public function __construct(
        private FailureRecorder $failures,
        private SimpleFailureRecoveryPolicy $recovery,
        private PipelineCheckpointStore $checkpoints,
        private PipelineDefinitionGraph $definitions,
        private AdvanceEstimateGeneration $advance,
        private AiEstimateQuotaService $quota,
        private Connection $database,
    ) {}

    public function handle(FailureExecutionSnapshot $snapshot, Throwable $error): void
    {
        $checkpoint = EstimateGenerationPipelineCheckpoint::query()
            ->where('session_id', $snapshot->sessionId)
            ->where('organization_id', $snapshot->organizationId)
            ->where('project_id', $snapshot->projectId)
            ->where('generation_attempt_id', $snapshot->attemptId)
            ->whereIn('status', [CheckpointStatus::Running->value, CheckpointStatus::Failed->value])
            ->orderByDesc('id')
            ->first();

        if ($checkpoint instanceof EstimateGenerationPipelineCheckpoint) {
            try {
                $failure = $this->failures->capture($error, new FailureContext(
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
                    eventId: is_string($checkpoint->claim_token) && $checkpoint->claim_token !== ''
                        ? $checkpoint->claim_token
                        : $snapshot->eventId,
                    expectedSessionStateVersion: $snapshot->stateVersion,
                    expectedSessionStatus: $snapshot->status,
                    checkpointId: (int) $checkpoint->getKey(),
                ));
                $definition = $this->definitions->get($checkpoint->stage);
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
                if ($checkpoint->status === CheckpointStatus::Failed) {
                    $this->recover($failure, (string) $checkpoint->base_input_version);
                } else {
                    $claim = CheckpointClaim::acquired(
                        $context,
                        $checkpoint->stage,
                        (string) $checkpoint->claim_token,
                        (int) $checkpoint->attempt_count,
                        (int) $checkpoint->getKey(),
                    );
                    if ($this->checkpoints->fail($claim, $error, new DateTimeImmutable)) {
                        $this->recover($failure, (string) $checkpoint->base_input_version);
                    }
                }
            } catch (Throwable) {
            }
        }

        Log::error('[EstimateGeneration] Draft generation job failed', [
            'session_id' => $snapshot->sessionId,
            'failure_code' => 'draft_generation_failed',
            'failure_fingerprint' => hash('sha256', $error::class.'|'.(string) $error->getCode()),
        ]);
    }

    private function recover(FailureData $failure, string $inputVersion): void
    {
        $this->database->transaction(function () use ($failure, $inputVersion): void {
            $session = EstimateGenerationSession::query()
                ->whereKey($failure->context->sessionId)
                ->where('organization_id', $failure->context->organizationId)
                ->where('project_id', $failure->context->projectId)
                ->lockForUpdate()
                ->first();
            if (! $session instanceof EstimateGenerationSession) {
                return;
            }

            $stateVersion = (int) $session->state_version;
            $hasUsableDraft = EstimateGenerationPackage::query()
                ->where('session_id', $session->getKey())
                ->where('input_version', $inputVersion)
                ->whereIn('status', ['ready_for_review', 'review_required', 'approved'])
                ->exists();
            $outcome = $this->recovery->decide($failure, $stateVersion, $session->status, $hasUsableDraft);

            if ($outcome === FailureRecoveryOutcome::NeedsInput) {
                if ($session->status === EstimateGenerationStatus::ProcessingDocuments) {
                    $this->advance->documentsNeedReview($session, $failure->code);
                } elseif ($session->status === EstimateGenerationStatus::Generating) {
                    $this->advance->generationNeedsReview($session, $failure->code);
                }

                return;
            }
            if ($outcome !== FailureRecoveryOutcome::Terminal) {
                return;
            }

            $failed = $this->advance->failed($session, $failure->code);
            if (! $hasUsableDraft) {
                $this->quota->releaseTechnicalFailure(
                    (string) $failed->organization_id,
                    (string) $failed->getKey(),
                );
            }
        }, 3);
    }
}
