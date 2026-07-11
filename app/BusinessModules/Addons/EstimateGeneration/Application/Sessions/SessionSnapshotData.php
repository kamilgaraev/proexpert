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
        public array $documentsSummary,
        public array $estimateSummary,
        public array $reviewSummary,
        public ?int $appliedEstimateId,
        public string $updatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'processing_stage' => $this->processingStage,
            'processing_progress' => $this->processingProgress,
            'state_version' => $this->stateVersion,
            'available_actions' => $this->availableActions,
            'blocking_issues' => $this->blockingIssues,
            'warnings' => $this->warnings,
            'next_action' => $this->nextAction,
            'documents_summary' => $this->documentsSummary,
            'estimate_summary' => $this->estimateSummary,
            'review_summary' => $this->reviewSummary,
            'applied_estimate_id' => $this->appliedEstimateId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
