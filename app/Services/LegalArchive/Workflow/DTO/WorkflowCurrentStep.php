<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

final readonly class WorkflowCurrentStep
{
    public function __construct(
        public int $id,
        public string $key,
        public string $label,
        public string $status,
        public int $sequence,
        public string $parallelGroup,
        public bool $required,
        public string $assigneeType,
        public string $assigneeReference,
        public ?string $dueAt,
        public bool $overdue,
        public int $lockVersion,
        public int $documentVersionId,
        public string $documentContentHash,
        public bool $assignedToCurrentActor,
        public ?string $activatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status,
            'sequence' => $this->sequence,
            'parallel_group' => $this->parallelGroup,
            'required' => $this->required,
            'assignee' => [
                'type' => $this->assigneeType,
                'reference' => $this->assigneeReference,
            ],
            'due_at' => $this->dueAt,
            'overdue' => $this->overdue,
            'lock_version' => $this->lockVersion,
            'expected_step_lock_version' => $this->lockVersion,
            'document_version_id' => $this->documentVersionId,
            'document_content_hash' => $this->documentContentHash,
            'assigned_to_current_actor' => $this->assignedToCurrentActor,
            'activated_at' => $this->activatedAt,
        ];
    }
}
