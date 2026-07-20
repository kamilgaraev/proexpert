<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

final readonly class WorkflowDecisionInput
{
    public function __construct(
        public string $action,
        public string $idempotencyKey,
        public int $expectedInstanceLockVersion,
        public int $expectedStepLockVersion,
        public ?string $comment = null,
        public ?string $reason = null,
        public ?string $reassignActorType = null,
        public ?string $reassignActorReference = null,
        public ?string $dueAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'action' => $this->action,
            'comment' => $this->comment,
            'reason' => $this->reason,
            'reassign_actor_type' => $this->reassignActorType,
            'reassign_actor_reference' => $this->reassignActorReference,
            'due_at' => $this->dueAt,
            'expected_instance_lock_version' => $this->expectedInstanceLockVersion,
            'expected_step_lock_version' => $this->expectedStepLockVersion,
        ];
    }
}
