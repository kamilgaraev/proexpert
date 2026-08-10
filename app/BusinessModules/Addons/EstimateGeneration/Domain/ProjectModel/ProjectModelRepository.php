<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

interface ProjectModelRepository
{
    public function appendEntities(array $entities, int $chunkSize = 500): void;

    public function appendFacts(array $facts, int $chunkSize = 500): void;

    public function appendConflicts(array $conflicts, int $chunkSize = 200): void;

    public function appendDecisions(array $decisions, int $chunkSize = 200): void;

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void;

    public function appendCrossDocumentLinks(array $links, int $chunkSize = 200): void;

    public function currentFacts(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?string $entityId = null,
    ): array;

    public function invalidateSourceVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $replacementSourceVersion,
    ): void;
}
