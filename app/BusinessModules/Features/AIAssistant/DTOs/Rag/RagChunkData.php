<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Rag;

use DateTimeInterface;

final readonly class RagChunkData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public string $sourceType,
        public string $entityType,
        public string|int $entityId,
        public string $title,
        public string $content,
        public array $metadata = [],
        public ?DateTimeInterface $updatedAt = null
    ) {
    }
}
