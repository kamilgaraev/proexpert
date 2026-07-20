<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

final readonly class WorkflowActionDetail
{
    /** @param list<string> $blockers */
    public function __construct(
        public string $action,
        public string $label,
        public string $permission,
        public bool $enabled,
        public array $blockers = [],
        public ?int $targetStepId = null,
        public ?int $expectedInstanceLockVersion = null,
        public ?int $expectedStepLockVersion = null,
        public string $key = '',
        public string $scope = 'document',
        public ?int $instanceId = null,
        public bool $requiresComment = false,
        public bool $requiresReason = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'label' => $this->label,
            'permission' => $this->permission,
            'enabled' => $this->enabled,
            'blockers' => $this->blockers,
            'target_step_id' => $this->targetStepId,
            'expected_instance_lock_version' => $this->expectedInstanceLockVersion,
            'expected_step_lock_version' => $this->expectedStepLockVersion,
            'key' => $this->key,
            'scope' => $this->scope,
            'instance_id' => $this->instanceId,
            'requires_comment' => $this->requiresComment,
            'requires_reason' => $this->requiresReason,
            'input_schema' => $this->inputSchema(),
        ];
    }

    /** @return array{required: list<string>, properties: array<string, array<string, mixed>>} */
    private function inputSchema(): array
    {
        return match ($this->action) {
            'reject', 'return' => [
                'required' => ['comment'],
                'properties' => [
                    'comment' => ['type' => 'string', 'required' => true, 'min_length' => 1],
                ],
            ],
            'reassign' => [
                'required' => ['reason', 'target_actor_type', 'target_actor_id'],
                'properties' => [
                    'reason' => ['type' => 'string', 'required' => true, 'min_length' => 1],
                    'target_actor_type' => [
                        'type' => 'string',
                        'required' => true,
                        'enum' => ['user', 'role', 'party', 'external'],
                    ],
                    'target_actor_id' => ['type' => 'string', 'required' => true, 'min_length' => 1],
                    'due_at' => ['type' => 'string', 'format' => 'date-time', 'required' => false, 'future' => true],
                ],
            ],
            'cancel' => [
                'required' => ['reason'],
                'properties' => [
                    'reason' => ['type' => 'string', 'required' => true, 'min_length' => 1],
                ],
            ],
            default => ['required' => [], 'properties' => []],
        };
    }
}
