<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

interface ProjectModelRepository
{
    /** @param list<string> $legacyStableKeys */
    public function resolveEntityStableKey(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $entityType,
        string $canonicalStableKey,
        array $legacyStableKeys,
    ): string;

    public function saveSourceModel(array $entities, array $facts, array $evidence, array $conflicts = []): void;

    public function applyDecision(Decision $decision, Fact $selectedFact): void;

    public function applyTechnologyDecision(
        Decision $decision,
        Fact $selectedFact,
        string $inputFingerprint,
        int $planningRunId,
    ): bool;

    public function applyCompletenessExclusionDecision(
        Decision $decision,
        Fact $selectedFact,
        string $inputFingerprint,
        int $completenessRunId,
    ): bool;

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void;

    public function replaceDerivedQuantityProjection(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        array $quantities,
        array $inactiveLogicalIds,
    ): void;

    /** @param list<DerivedQuantity> $quantities */
    public function replaceDerivedQuantityFormulaProjectionSet(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $formulaVersion,
        array $quantities,
    ): void;

    public function deactivateDerivedQuantityProjectionScope(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?string $sourceVersion = null,
    ): void;

    /** @return list<DerivedQuantity> */
    public function currentDerivedQuantities(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        int $limit = 200,
    ): array;

    /** @return list<string> */
    public function currentDerivedQuantityLogicalIdsByFormulaVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $formulaVersion,
    ): array;

    /** @return list<DerivedQuantity> */
    public function currentDerivedQuantitiesForFormulaVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $formulaVersion,
        int $limit = 200,
    ): array;

    /** @return list<DerivedQuantity> */
    public function derivedQuantityHistory(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $logicalId,
        int $limit = 200,
    ): array;

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

    /** @return list<Decision> */
    public function decisionsForSelectedFacts(int $organizationId, int $projectId, int $sessionId, array $factIds): array;

    /** @param list<string> $sourceVersions @return array{arbiter:list<string>,geometry_expert:list<string>} */
    public function completedSynthesisRoleFingerprints(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $sourceVersions,
    ): array;

    public function currentConflicts(int $organizationId, int $projectId, int $sessionId): array;

    public function replaceUnderstanding(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        string $snapshotToken,
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
        string $snapshotToken,
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
