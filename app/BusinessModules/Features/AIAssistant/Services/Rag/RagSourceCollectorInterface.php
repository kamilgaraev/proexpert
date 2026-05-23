<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;

interface RagSourceCollectorInterface
{
    public function sourceType(): string;

    public function enabled(): bool;

    /**
     * @return iterable<RagChunkData>
     */
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable;

    /**
     * @return iterable<RagChunkData>
     */
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable;
}
