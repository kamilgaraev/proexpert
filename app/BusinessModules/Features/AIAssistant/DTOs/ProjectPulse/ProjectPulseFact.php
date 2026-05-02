<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse;

final readonly class ProjectPulseFact
{
    public function __construct(
        public string $id,
        public string $type,
        public string $priority,
        public string $title,
        public string $text,
        public ?int $projectId = null,
        public ?string $projectName = null,
        public ?array $relatedEntity = null,
        public ?float $amount = null,
        public ?string $occurredAt = null,
        public string $source = 'system',
        public string $category = 'system',
        public ?string $status = null,
        public ?string $nextAction = null,
        public ?array $primaryAction = null,
        public ?string $deadline = null,
        public ?int $ageDays = null,
        public ?string $ownerName = null,
        public array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'title' => $this->title,
            'text' => $this->text,
            'next_action' => $this->nextAction,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'related_entity' => $this->relatedEntity,
            'primary_action' => $this->primaryAction,
            'amount' => $this->amount,
            'deadline' => $this->deadline,
            'age_days' => $this->ageDays,
            'owner_name' => $this->ownerName,
            'occurred_at' => $this->occurredAt,
            'meta' => $this->meta,
        ];
    }
}
