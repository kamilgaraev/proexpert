<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Rag;

use DateTimeInterface;

final readonly class RagSearchResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceType,
        public string $entityType,
        public string|int $entityId,
        public ?int $projectId,
        public string $title,
        public string $excerpt,
        public float $similarity,
        public array $metadata = [],
        public ?DateTimeInterface $updatedAt = null
    ) {
    }
}
