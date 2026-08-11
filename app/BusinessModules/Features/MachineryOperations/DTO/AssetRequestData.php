<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\DTO;

final readonly class AssetRequestData
{
    /** @param array<string, mixed> $requiredProfile */
    public function __construct(
        public int $projectId,
        public string $plannedStartAt,
        public ?string $plannedEndAt,
        public string $purpose,
        public string $priority = 'normal',
        public ?int $scheduleTaskId = null,
        public array $requiredProfile = [],
    ) {}
}
