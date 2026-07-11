<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;

final readonly class SessionSnapshotData
{
    public function __construct(
        public int $id,
        public EstimateGenerationStatus $status,
        public string $processingStage,
        public int $processingProgress,
        public int $stateVersion,
        public array $availableActions,
        public array $blockingIssues,
        public array $warnings,
        public ?string $nextAction,
        public bool $readinessEvaluated,
        public array $documentsSummary,
        public array $estimateSummary,
        public array $reviewSummary,
        public ?int $appliedEstimateId,
        public string $updatedAt,
        public int $projectId = 0,
        public string $operationalVersion = '',
        public bool $canGenerate = false,
        public bool $canApply = false,
        public array $currentCheckpoint = [],
        public array $queueSummary = [],
        public array $recoverySummary = [],
        public array $evidenceSummary = [],
        public array $qualitySummary = [],
        public array $usageSummary = [],
        public array $failureSummary = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $base = [
            'id' => $this->id,
            'status' => $this->status->value,
            'processing_stage' => $this->processingStage,
            'processing_progress' => $this->processingProgress,
            'state_version' => $this->stateVersion,
            'available_actions' => $this->availableActions,
            'blocking_issues' => $this->blockingIssues,
            'warnings' => $this->warnings,
            'next_action' => $this->nextAction,
            'readiness_evaluated' => $this->readinessEvaluated,
            'documents_summary' => $this->documentsSummary,
            'estimate_summary' => $this->estimateSummary,
            'review_summary' => $this->reviewSummary,
            'applied_estimate_id' => $this->appliedEstimateId,
            'updated_at' => $this->updatedAt,
        ];

        if ($this->operationalVersion === '') {
            return $base;
        }

        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'status' => $this->status->value,
            'processing_stage' => $this->processingStage,
            'processing_progress' => $this->processingProgress,
            'state_version' => $this->stateVersion,
            'operational_version' => $this->operationalVersion,
            'available_actions' => $this->availableActions,
            'blocking_issues' => $this->blockingIssues,
            'warnings' => $this->warnings,
            'next_action' => $this->nextAction,
            'readiness_evaluated' => $this->readinessEvaluated,
            'can_generate' => $this->canGenerate,
            'can_apply' => $this->canApply,
            'current_checkpoint' => $this->currentCheckpoint,
            'queue_summary' => $this->queueSummary,
            'recovery_summary' => $this->recoverySummary,
            'documents_summary' => $this->documentsSummary,
            'estimate_summary' => $this->estimateSummary,
            'review_summary' => $this->reviewSummary,
            'evidence_summary' => $this->evidenceSummary,
            'quality_summary' => $this->qualitySummary,
            'usage_summary' => $this->usageSummary,
            'failure_summary' => $this->failureSummary,
            'applied_estimate_id' => $this->appliedEstimateId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
