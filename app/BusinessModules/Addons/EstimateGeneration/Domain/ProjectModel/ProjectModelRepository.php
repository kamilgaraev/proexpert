<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

interface ProjectModelRepository
{
    public function saveSourceModel(array $entities, array $facts, array $evidence, array $conflicts = []): void;

    public function applyDecision(Decision $decision, Fact $selectedFact): void;

    public function applyTechnologyDecision(
        Decision $decision,
        Fact $selectedFact,
        string $inputFingerprint,
        int $planningRunId,
    ): bool;

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void;

    public function snapshot(int $organizationId, int $projectId, int $sessionId, ?int $factLimit = null): ProjectModelSnapshot;

    /** @return array{snapshot:ProjectModelSnapshot,token:string} */
    public function snapshotForUnderstanding(int $organizationId, int $projectId, int $sessionId, int $factLimit): array;

    public function understandingPreflight(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $maxFacts,
        int $maxEvidenceItems,
        int $maxEvidencePerFact,
        int $maxEvidencePayloadBytes,
        int $maxEvidenceBytesPerItem,
    ): array;

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

    /** @return list<Decision> */
    public function decisions(int $organizationId, int $projectId, int $sessionId, array $decisionIds): array;

    public function currentConflicts(int $organizationId, int $projectId, int $sessionId): array;

    public function replaceUnderstanding(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        array $links,
        array $conflicts,
        array $questions,
        array $limitations,
        int $providerCalls,
    ): bool;

    public function replayUnderstanding(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
    ): ?array;

    public function currentUnderstanding(int $organizationId, int $projectId, int $sessionId): ?array;

    /** @return array{snapshot:ProjectModelSnapshot,token:string} */
    public function snapshotForPlanning(int $organizationId, int $projectId, int $sessionId, int $factLimit): array;

    public function replaceTechnologyRecommendations(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        string $catalogVersion,
        string $catalogHash,
        array $recommendations,
        array $limitations,
    ): bool;

    public function replayTechnologyRecommendations(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        string $catalogVersion,
        string $catalogHash,
    ): ?array;

    public function currentTechnologyRecommendations(int $organizationId, int $projectId, int $sessionId): ?array;

    public function replaceCompleteness(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        string $catalogVersion,
        string $catalogHash,
        string $ruleCatalogVersion,
        string $ruleCatalogHash,
        array $findings,
        array $limitations,
    ): bool;

    public function replayCompleteness(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        string $catalogVersion,
        string $catalogHash,
        string $ruleCatalogVersion,
        string $ruleCatalogHash,
    ): ?array;

    public function currentCompleteness(int $organizationId, int $projectId, int $sessionId): ?array;

    public function invalidateSourceVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $replacementSourceVersion,
    ): void;
}
