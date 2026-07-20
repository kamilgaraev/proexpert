<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

use DomainException;

final readonly class WorkflowSummary
{
    /**
     * @param  list<string>  $problemFlags
     * @param  list<WorkflowActionDetail>  $availableActionDetails
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
    ) {}

    public function action(string $action): WorkflowActionDetail
    {
        foreach ($this->availableActionDetails as $detail) {
            if ($detail->action === $action) {
                return $detail;
            }
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
            ],
            'problem_flags' => $this->problemFlags,
            'available_action_details' => array_map(
                static fn (WorkflowActionDetail $detail): array => $detail->toArray(),
                $this->availableActionDetails,
            ),
        ];
    }
}
