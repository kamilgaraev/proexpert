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
        ];
    }
}
