<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

use DomainException;

final readonly class WorkflowSummary
{
    /**
     * @param  list<string>  $problemFlags
     * @param  list<WorkflowActionDetail>  $availableActionDetails
     * @param  list<WorkflowCurrentStep>  $currentSteps
     */
    public function __construct(
        public string $status,
        public string $statusLabel,
        public ?int $instanceId,
        public ?int $documentVersionId,
        public ?string $documentContentHash,
        public ?string $dueAt,
        public array $problemFlags,
        public array $availableActionDetails,
        public array $currentSteps = [],
        public ?int $expectedInstanceLockVersion = null,
    ) {}

    public function action(string $action, ?int $targetStepId = null): WorkflowActionDetail
    {
        $matches = [];
        foreach ($this->availableActionDetails as $detail) {
            if ($detail->action === $action && ($targetStepId === null || $detail->targetStepId === $targetStepId)) {
                $matches[] = $detail;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new DomainException('legal_workflow_action_ambiguous');
        }

        throw new DomainException('legal_workflow_action_not_available');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workflow_summary' => [
                'status' => $this->status,
                'status_label' => $this->statusLabel,
                'instance_id' => $this->instanceId,
                'document_version_id' => $this->documentVersionId,
                'document_content_hash' => $this->documentContentHash,
                'due_at' => $this->dueAt,
                'expected_instance_lock_version' => $this->expectedInstanceLockVersion,
                'problem_flags' => $this->problemFlags,
                'available_action_details' => array_map(
                    static fn (WorkflowActionDetail $detail): array => $detail->toArray(),
                    $this->availableActionDetails,
                ),
                'current_steps' => array_map(
                    static fn (WorkflowCurrentStep $step): array => $step->toArray(),
                    $this->currentSteps,
                ),
            ],
        ];
    }
}
