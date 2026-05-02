<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse;

final readonly class ProjectPulseRecommendation
{
    public function __construct(
        public string $id,
        public string $priority,
        public string $title,
        public string $action,
        public string $reason,
        public string $expectedEffect,
        public ?int $projectId,
        public ?string $route,
        public string $source,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'priority' => $this->priority,
            'title' => $this->title,
            'action' => $this->action,
            'reason' => $this->reason,
            'expected_effect' => $this->expectedEffect,
            'project_id' => $this->projectId,
            'route' => $this->route,
            'source' => $this->source,
        ];
    }
}
