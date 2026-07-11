<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentUnitExecutionContext
{
    /** @param array<string, scalar|null> $locator */
    public function __construct(
        public int $unitId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $documentId,
        public DocumentUnitType $type,
        public int $index,
        public string $sourceVersion,
        public array $locator,
        public string $storagePath,
        public string $mimeType,
        public string $filename,
    ) {}
}
