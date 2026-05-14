<?php

declare(strict_types=1);

namespace App\DTOs\Workflow;

final readonly class WorkflowSurfaceData
{
    /**
     * @param array<int, string> $availableActions
     * @param array<int, array<string, mixed>> $problemFlags
     * @param array<int, string> $blockers
     * @param array<int, string> $warnings
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $stage,
        public string $stageLabel,
        public string $status,
        public string $statusLabel,
        public ?string $nextAction,
        public ?string $nextActionLabel,
        public array $availableActions = [],
        public array $problemFlags = [],
        public array $blockers = [],
        public array $warnings = [],
        public array $meta = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'stage_label' => $this->stageLabel,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'next_action' => $this->nextAction,
            'next_action_label' => $this->nextActionLabel,
            'available_actions' => $this->availableActions,
            'problem_flags' => $this->problemFlags,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }
}
