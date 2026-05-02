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
        public array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'priority' => $this->priority,
            'title' => $this->title,
            'text' => $this->text,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'related_entity' => $this->relatedEntity,
            'amount' => $this->amount,
            'occurred_at' => $this->occurredAt,
            'meta' => $this->meta,
        ];
    }
}
