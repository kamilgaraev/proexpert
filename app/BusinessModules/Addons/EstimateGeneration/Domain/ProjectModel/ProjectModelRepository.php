<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

interface ProjectModelRepository
{
    public function saveSourceModel(array $entities, array $facts, array $evidence, array $conflicts = []): void;

    public function applyDecision(Decision $decision, Fact $selectedFact): void;

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void;

    public function snapshot(int $organizationId, int $projectId, int $sessionId, ?int $factLimit = null): ProjectModelSnapshot;

    public function currentFacts(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?string $entityId = null,
        ?int $limit = null,
    ): array;

    public function fact(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $factId,
    ): ?Fact;

    public function currentConflicts(int $organizationId, int $projectId, int $sessionId): array;

    public function replaceUnderstanding(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        array $links,
        array $conflicts,
        array $questions,
        array $limitations,
        int $providerCalls,
    ): void;

    public function currentUnderstanding(int $organizationId, int $projectId, int $sessionId): ?array;

    public function invalidateSourceVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $replacementSourceVersion,
    ): void;
}
