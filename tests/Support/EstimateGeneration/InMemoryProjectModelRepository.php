<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use Closure;
use InvalidArgumentException;

final class InMemoryProjectModelRepository implements ProjectModelRepository
{
    public ?int $lastSnapshotFactLimit = null;

    public ?bool $understandingWithinBudget = null;

    public int $understandingPreflightCalls = 0;

    public int $snapshotCalls = 0;

    public array $entities = [];

    public array $facts = [];

    public array $evidence = [];

    public array $conflicts = [];

    public array $decisions = [];

    public array $quantities = [];

    public array $currentQuantities = [];

    public array $links = [];

    public array $understanding = [];

    public array $technologyPlanningHistory = [];

    public int $technologyPlanningWriteCount = 0;

    public array $completenessHistory = [];

    public int $completenessWriteCount = 0;

    public ?Closure $beforeUnderstandingSave = null;

    public ?Closure $beforeTechnologyReplayLock = null;

    public ?Closure $beforeCompletenessReplayLock = null;

    public ?Closure $beforeTechnologyDecisionLock = null;

    public ?Closure $beforeCompletenessDecisionLock = null;

    private array $projection = [];

    public function saveSourceModel(array $entities, array $facts, array $evidence, array $conflicts = []): void
    {
        $modelRecords = [...$entities, ...$facts, ...$conflicts];
        $scopeRecord = $modelRecords[0] ?? null;
        foreach ($modelRecords as $record) {
            if (! is_object($record) || $scopeRecord === null
                || $this->scope($record) !== $this->scope($scopeRecord)
                || $record->sourceVersion !== $scopeRecord->sourceVersion) {
                throw new InvalidArgumentException('Cross-scope source model.');
            }
        }
        $availableEvidence = array_fill_keys(array_keys($this->evidence), true);
        foreach ($evidence as $item) {
            if ($item instanceof Evidence) {
                if ($scopeRecord !== null && $this->scope($item) !== $this->scope($scopeRecord)) {
                    throw new InvalidArgumentException('Cross-scope evidence.');
                }
                $availableEvidence[$this->recordKey($item)] = true;
            }
        }
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Fact expected.');
            }
            foreach ($fact->evidenceIds as $evidenceId) {
                $key = implode(':', [...$this->scope($fact), $fact->sourceVersion, $evidenceId]);
                $sourceAgnostic = array_filter(
                    array_keys($availableEvidence),
                    static fn (string $item): bool => str_starts_with($item, implode(':', [$fact->organizationId, $fact->projectId, $fact->sessionId]).':')
                        && str_ends_with($item, ':'.$evidenceId),
                );
                if (! isset($availableEvidence[$key]) && $sourceAgnostic === []) {
                    throw new InvalidArgumentException('Fact evidence is outside the atomic source model.');
                }
            }
        }
        foreach ($entities as $entity) {
            if (! $entity instanceof Entity) {
                throw new InvalidArgumentException('Entity expected.');
            }
            $this->entities[$this->recordKey($entity)] = $entity;
        }
        foreach ($evidence as $item) {
            if (! $item instanceof Evidence) {
                throw new InvalidArgumentException('Evidence expected.');
            }
            $this->evidence[$this->recordKey($item)] = $item;
        }
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Fact expected.');
            }
            $this->facts[$this->recordKey($fact)] = $fact;
            if (in_array($fact->status, ['confirmed', 'conflicted', 'unresolved'], true)) {
                $this->projection[$this->logicalKey($fact)] = $fact->id;
            }
        }
        foreach ($conflicts as $conflict) {
            if (! $conflict instanceof Conflict) {
                throw new InvalidArgumentException('Conflict expected.');
            }
            $this->conflicts[$this->recordKey($conflict)] = $conflict;
        }
    }

    public function applyDecision(Decision $decision, Fact $selectedFact): void
    {
        $this->assertSameScope($decision, $selectedFact);
        $this->saveSourceModel([], [$selectedFact], [], []);
        $this->decisions[$this->recordKey($decision)] = $decision;
        $prefix = implode(':', $this->scope($decision)).':';
        $this->links = array_filter($this->links, static fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
        foreach ($this->understanding as $key => $run) {
            if (str_starts_with($key, $prefix)) {
                $this->understanding[$key]['is_current'] = false;
            }
        }
        foreach ($this->technologyPlanningHistory as $key => $run) {
            if (str_starts_with($key, $prefix)) {
                $this->technologyPlanningHistory[$key]['is_current'] = false;
            }
        }
        foreach ($this->completenessHistory as $key => $run) {
            if (str_starts_with($key, $prefix)) {
                $this->completenessHistory[$key]['is_current'] = false;
            }
        }
    }

    public function applyTechnologyDecision(
        Decision $decision,
        Fact $selectedFact,
        string $inputFingerprint,
        int $planningRunId,
    ): bool {
        if ($this->beforeTechnologyDecisionLock !== null) {
            $hook = $this->beforeTechnologyDecisionLock;
            $this->beforeTechnologyDecisionLock = null;
            $hook();
        }
        $current = $this->currentTechnologyRecommendations(
            $decision->organizationId,
            $decision->projectId,
            $decision->sessionId,
        );
        if ($current === null || ($current['run_id'] ?? null) !== $planningRunId
            || ! hash_equals($inputFingerprint, $current['input_fingerprint'])
            || ! hash_equals($inputFingerprint, $this->understandingSnapshotToken(
                $decision->organizationId,
                $decision->projectId,
                $decision->sessionId,
            ))) {
            return false;
        }
        $this->applyDecision($decision, $selectedFact);

        return true;
    }

    public function applyCompletenessExclusionDecision(
        Decision $decision,
        Fact $selectedFact,
        string $inputFingerprint,
        int $completenessRunId,
    ): bool {
        if ($this->beforeCompletenessDecisionLock !== null) {
            $hook = $this->beforeCompletenessDecisionLock;
            $this->beforeCompletenessDecisionLock = null;
            $hook();
        }
        $current = $this->currentCompleteness(
            $decision->organizationId,
            $decision->projectId,
            $decision->sessionId,
        );
        if ($current === null || ($current['run_id'] ?? null) !== $completenessRunId
            || ! hash_equals($inputFingerprint, (string) ($current['input_fingerprint'] ?? ''))
            || ! hash_equals($inputFingerprint, $this->understandingSnapshotToken(
                $decision->organizationId,
                $decision->projectId,
                $decision->sessionId,
            ))) {
            return false;
        }
        $this->applyDecision($decision, $selectedFact);

        return true;
    }

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void
    {
        foreach ($quantities as $quantity) {
            if (! $quantity instanceof DerivedQuantity) {
                throw new InvalidArgumentException('Derived quantity expected.');
            }
            foreach ($quantity->operands as $operand) {
                $fact = $this->factById($quantity, $operand['fact_id']);
                $projected = $this->projection[$this->logicalKey($fact)] ?? null;
                if ($projected !== $fact->id || $fact->version !== $operand['projection_version']
                    || $fact->status !== $operand['status']) {
                    throw new InvalidArgumentException('Derived quantity operand is not the current projection.');
                }
            }
            if ($quantity->exactIdentity === null
                || ! hash_equals($quantity->exactIdentity, DerivedQuantityIdentity::for($quantity))) {
                throw new InvalidArgumentException('Derived quantity exact identity does not match its content.');
            }
            $recordKey = $this->recordKey($quantity);
            $existing = $this->quantities[$recordKey] ?? null;
            if ($existing instanceof DerivedQuantity) {
                if ($existing != $quantity) {
                    throw new InvalidArgumentException('Derived quantity exact identity collision.');
                }

                continue;
            }
            $this->quantities[$recordKey] = $quantity;
            $logicalKey = implode(':', [
                $quantity->organizationId,
                $quantity->projectId,
                $quantity->sessionId,
                $quantity->sourceVersion,
                $quantity->logicalId,
            ]);
            $this->currentQuantities[$logicalKey] = $quantity;
        }
    }

    public function snapshot(int $organizationId, int $projectId, int $sessionId, ?int $factLimit = null): ProjectModelSnapshot
    {
        $this->snapshotCalls++;
        $this->lastSnapshotFactLimit = $factLimit;
        $scope = [$organizationId, $projectId, $sessionId];
        $entities = array_values(array_filter($this->entities, fn (Entity $item): bool => $this->scope($item) === $scope));
        $facts = $this->currentFacts($organizationId, $projectId, $sessionId, limit: $factLimit);
        $evidence = array_values(array_filter($this->evidence, fn (Evidence $item): bool => $this->scope($item) === $scope));
        $conflicts = $this->currentConflicts($organizationId, $projectId, $sessionId);

        return new ProjectModelSnapshot($entities, $facts, $evidence, $conflicts);
    }

    public function snapshotForUnderstanding(int $organizationId, int $projectId, int $sessionId, int $factLimit): array
    {
        return [
            'snapshot' => $this->snapshot($organizationId, $projectId, $sessionId, $factLimit),
            'token' => $this->understandingSnapshotToken($organizationId, $projectId, $sessionId),
        ];
    }

    public function understandingPreflight(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $maxFacts,
        int $maxEvidenceItems,
        int $maxEvidencePerFact,
        int $maxEvidencePayloadBytes,
        int $maxEvidenceBytesPerItem,
    ): array {
        $this->understandingPreflightCalls++;
        $facts = [];
        $source = null;
        foreach ($this->facts as $fact) {
            if ($this->scope($fact) !== [$organizationId, $projectId, $sessionId]
                || ($this->projection[$this->logicalKey($fact)] ?? null) !== $fact->id) {
                continue;
            }
            $facts[] = $fact;
            $source = $fact->sourceVersion;
        }
        $evidence = [];
        $maxPerFact = 0;
        foreach ($facts as $fact) {
            $maxPerFact = max($maxPerFact, count($fact->evidenceIds));
            foreach ($fact->evidenceIds as $evidenceId) {
                foreach ($this->evidence as $item) {
                    if ($item->id === $evidenceId && $this->scope($item) === [$organizationId, $projectId, $sessionId]) {
                        $evidence[$item->id] = $item;
                    }
                }
            }
        }
        $sizes = array_map(
            static fn (Evidence $item): int => strlen(json_encode(get_object_vars($item), JSON_THROW_ON_ERROR)),
            $evidence,
        );
        $withinBudget = count($facts) <= $maxFacts
            && count($evidence) <= $maxEvidenceItems
            && $maxPerFact <= $maxEvidencePerFact
            && array_sum($sizes) <= $maxEvidencePayloadBytes
            && ($sizes === [] || max($sizes) <= $maxEvidenceBytesPerItem);

        return [
            'within_budget' => $this->understandingWithinBudget ?? $withinBudget,
            'source_version' => $source,
            'fact_count' => count($facts),
            'evidence_count' => count($evidence),
            'max_evidence_per_fact' => $maxPerFact,
            'total_payload_bytes' => array_sum($sizes),
            'max_payload_bytes' => $sizes === [] ? 0 : max($sizes),
        ];
    }

    public function currentFacts(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?string $entityId = null,
        ?int $limit = null,
    ): array {
        $result = [];
        foreach ($this->facts as $fact) {
            if ($this->scope($fact) !== [$organizationId, $projectId, $sessionId]
                || ($entityId !== null && $fact->entityId !== $entityId)
                || ($this->projection[$this->logicalKey($fact)] ?? null) !== $fact->id) {
                continue;
            }
            $result[] = $fact;
        }
        usort($result, static fn (Fact $left, Fact $right): int => [$left->entityId, $left->type] <=> [$right->entityId, $right->type]);

        return $limit === null ? $result : array_slice($result, 0, $limit);
    }

    public function fact(int $organizationId, int $projectId, int $sessionId, string $factId): ?Fact
    {
        foreach ($this->facts as $fact) {
            if ($this->scope($fact) === [$organizationId, $projectId, $sessionId] && $fact->id === $factId) {
                return $fact;
            }
        }

        return null;
    }

    public function decisions(int $organizationId, int $projectId, int $sessionId, array $decisionIds): array
    {
        $ids = array_fill_keys($decisionIds, true);

        return array_values(array_filter(
            $this->decisions,
            fn (Decision $decision): bool => $this->scope($decision) === [$organizationId, $projectId, $sessionId]
                && isset($ids[$decision->id]),
        ));
    }

    public function decisionsForSelectedFacts(int $organizationId, int $projectId, int $sessionId, array $factIds): array
    {
        $ids = array_fill_keys(array_slice(array_values(array_unique($factIds)), 0, 100), true);

        return array_values(array_filter(
            $this->decisions,
            fn (Decision $decision): bool => $this->scope($decision) === [$organizationId, $projectId, $sessionId]
                && $decision->selectedFactId !== null
                && isset($ids[$decision->selectedFactId]),
        ));
    }

    public function currentConflicts(int $organizationId, int $projectId, int $sessionId): array
    {
        return array_values(array_filter(
            $this->conflicts,
            fn (Conflict $item): bool => $this->scope($item) === [$organizationId, $projectId, $sessionId]
                && $item->status === 'unresolved',
        ));
    }

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
    ): bool {
        if ($this->beforeUnderstandingSave !== null) {
            $hook = $this->beforeUnderstandingSave;
            $this->beforeUnderstandingSave = null;
            $hook();
        }
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
            return false;
        }
        $scope = implode(':', [$organizationId, $projectId, $sessionId]);
        $encoded = json_encode([$links, $conflicts, $questions, $limitations, $providerCalls], JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $inputFingerprint."\0".$encoded);
        $key = implode(':', [$scope, $sourceVersion, $fingerprint]);
        $existing = $this->understanding[$key] ?? null;
        if ($existing !== null && (
            ! hash_equals($existing['input_fingerprint'], $inputFingerprint)
            || [$existing['links'], $existing['conflicts'], $existing['questions'], $existing['limitations'], $existing['provider_calls']]
                !== [$links, $conflicts, $questions, $limitations, $providerCalls]
        )) {
            throw new InvalidArgumentException('Project understanding fingerprint collision.');
        }
        foreach ($this->understanding as $existingKey => $run) {
            if (str_starts_with($existingKey, $scope.':')) {
                $this->understanding[$existingKey]['is_current'] = false;
            }
        }
        $this->links[$key] = $links;
        foreach ($conflicts as $conflict) {
            $this->conflicts[$this->recordKey($conflict)] = $conflict;
        }
        $this->understanding[$key] = [
            'source_version' => $sourceVersion,
            'input_fingerprint' => $inputFingerprint,
            'links' => $links,
            'conflicts' => $conflicts,
            'questions' => $questions,
            'limitations' => $limitations,
            'provider_calls' => $providerCalls,
            'is_current' => true,
        ];

        return true;
    }

    public function replayUnderstanding(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
    ): ?array {
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
            return null;
        }
        $scope = implode(':', [$organizationId, $projectId, $sessionId]);
        $match = null;
        $matchKey = null;
        foreach ($this->understanding as $key => $run) {
            if (str_starts_with($key, $scope.':'.$sourceVersion.':')
                && hash_equals($run['input_fingerprint'], $inputFingerprint)) {
                $match = $run;
                $matchKey = $key;
            }
        }
        if ($matchKey === null) {
            return null;
        }
        foreach ($this->understanding as $key => $run) {
            if (str_starts_with($key, $scope.':')) {
                $this->understanding[$key]['is_current'] = $key === $matchKey;
            }
        }
        $this->links[$scope.':'.$sourceVersion] = $match['links'];

        return $this->understanding[$matchKey];
    }

    public function currentUnderstanding(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $prefix = implode(':', [$organizationId, $projectId, $sessionId]).':';
        $matches = array_filter(
            $this->understanding,
            static fn (array $run, string $key): bool => str_starts_with($key, $prefix) && $run['is_current'],
            ARRAY_FILTER_USE_BOTH,
        );

        return $matches === [] ? null : end($matches);
    }

    public function snapshotForPlanning(int $organizationId, int $projectId, int $sessionId, int $factLimit): array
    {
        return $this->snapshotForUnderstanding($organizationId, $projectId, $sessionId, $factLimit);
    }

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
    ): bool {
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
            return false;
        }
        $scope = implode(':', [$organizationId, $projectId, $sessionId]);
        $key = implode(':', [$scope, $sourceVersion, $inputFingerprint, $catalogVersion, $catalogHash]);
        if (isset($this->technologyPlanningHistory[$key])) {
            if ($this->latestProjectionKey($this->technologyPlanningHistory, $scope, $sourceVersion, $inputFingerprint) !== $key) {
                return false;
            }
            $existing = $this->technologyPlanningHistory[$key];
            if ([$existing['recommendations'], $existing['limitations']] !== [$recommendations, $limitations]) {
                throw new InvalidArgumentException('Technology recommendation replay content differs.');
            }

            return true;
        }
        foreach ($this->technologyPlanningHistory as $existingKey => $run) {
            if (str_starts_with($existingKey, $scope.':')) {
                $this->technologyPlanningHistory[$existingKey]['is_current'] = false;
            }
        }
        $this->technologyPlanningHistory[$key] = [
            'run_id' => count($this->technologyPlanningHistory) + 1,
            'source_version' => $sourceVersion,
            'input_fingerprint' => $inputFingerprint,
            'catalog_version' => $catalogVersion,
            'catalog_hash' => $catalogHash,
            'recommendations' => $recommendations,
            'limitations' => $limitations,
            'is_current' => true,
        ];
        $this->technologyPlanningWriteCount++;

        return true;
    }

    public function replayTechnologyRecommendations(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        string $catalogVersion,
        string $catalogHash,
    ): ?array {
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
            return null;
        }
        $key = implode(':', [
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $catalogVersion,
            $catalogHash,
        ]);
        if (! isset($this->technologyPlanningHistory[$key])) {
            return null;
        }
        if ($this->beforeTechnologyReplayLock !== null) {
            $hook = $this->beforeTechnologyReplayLock;
            $this->beforeTechnologyReplayLock = null;
            $hook();
        }
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))
            || $this->latestProjectionKey($this->technologyPlanningHistory, implode(':', [$organizationId, $projectId, $sessionId]), $sourceVersion, $inputFingerprint) !== $key) {
            return null;
        }
        $prefix = implode(':', [$organizationId, $projectId, $sessionId]).':';
        foreach ($this->technologyPlanningHistory as $existingKey => $run) {
            if (str_starts_with($existingKey, $prefix)) {
                $this->technologyPlanningHistory[$existingKey]['is_current'] = $existingKey === $key;
            }
        }

        return $this->technologyPlanningHistory[$key];
    }

    public function currentTechnologyRecommendations(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $prefix = implode(':', [$organizationId, $projectId, $sessionId]).':';
        foreach (array_reverse($this->technologyPlanningHistory, true) as $key => $run) {
            if (str_starts_with($key, $prefix) && $run['is_current']) {
                return hash_equals($run['input_fingerprint'], $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))
                    ? $run
                    : null;
            }
        }

        return null;
    }

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
    ): bool {
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
            return false;
        }
        $scope = implode(':', [$organizationId, $projectId, $sessionId]);
        $key = implode(':', [$scope, $sourceVersion, $inputFingerprint, $catalogVersion, $catalogHash, $ruleCatalogVersion, $ruleCatalogHash]);
        if (isset($this->completenessHistory[$key])) {
            if ($this->latestProjectionKey($this->completenessHistory, $scope, $sourceVersion, $inputFingerprint) !== $key) {
                return false;
            }
            $existing = $this->completenessHistory[$key];
            if ([$existing['findings'], $existing['limitations']] !== [$findings, $limitations]) {
                throw new InvalidArgumentException('Completeness replay content differs.');
            }

            return true;
        }
        foreach ($this->completenessHistory as $existingKey => $run) {
            if (str_starts_with($existingKey, $scope.':')) {
                $this->completenessHistory[$existingKey]['is_current'] = false;
            }
        }
        $this->completenessHistory[$key] = [
            'run_id' => count($this->completenessHistory) + 1,
            'source_version' => $sourceVersion,
            'input_fingerprint' => $inputFingerprint,
            'catalog_version' => $catalogVersion,
            'catalog_hash' => $catalogHash,
            'rule_catalog_version' => $ruleCatalogVersion,
            'rule_catalog_hash' => $ruleCatalogHash,
            'findings' => $findings,
            'limitations' => $limitations,
            'is_current' => true,
        ];
        $this->completenessWriteCount++;

        return true;
    }

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
    ): ?array {
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
            return null;
        }
        $key = implode(':', [$organizationId, $projectId, $sessionId, $sourceVersion, $inputFingerprint, $catalogVersion, $catalogHash, $ruleCatalogVersion, $ruleCatalogHash]);
        if (! isset($this->completenessHistory[$key])) {
            return null;
        }
        if ($this->beforeCompletenessReplayLock !== null) {
            $hook = $this->beforeCompletenessReplayLock;
            $this->beforeCompletenessReplayLock = null;
            $hook();
        }
        if (! hash_equals($inputFingerprint, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))
            || $this->latestProjectionKey($this->completenessHistory, implode(':', [$organizationId, $projectId, $sessionId]), $sourceVersion, $inputFingerprint) !== $key) {
            return null;
        }
        $prefix = implode(':', [$organizationId, $projectId, $sessionId]).':';
        foreach ($this->completenessHistory as $existingKey => $run) {
            if (str_starts_with($existingKey, $prefix)) {
                $this->completenessHistory[$existingKey]['is_current'] = $existingKey === $key;
            }
        }

        return $this->completenessHistory[$key];
    }

    public function currentCompleteness(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $prefix = implode(':', [$organizationId, $projectId, $sessionId]).':';
        foreach (array_reverse($this->completenessHistory, true) as $key => $run) {
            if (str_starts_with($key, $prefix) && $run['is_current']) {
                return hash_equals($run['input_fingerprint'], $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))
                    ? $run
                    : null;
            }
        }

        return null;
    }

    public function invalidateSourceVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $replacementSourceVersion,
    ): void {
        foreach ($this->facts as $fact) {
            $usesReplacedEvidence = false;
            foreach ($fact->evidenceIds as $evidenceId) {
                foreach ($this->evidence as $item) {
                    if ($item->id === $evidenceId && $item->sourceVersion === $sourceVersion
                        && $this->scope($item) === [$organizationId, $projectId, $sessionId]) {
                        $usesReplacedEvidence = true;
                        break 2;
                    }
                }
            }
            if ($this->scope($fact) === [$organizationId, $projectId, $sessionId]
                && ($fact->sourceVersion === $sourceVersion || $usesReplacedEvidence)) {
                unset($this->projection[$this->logicalKey($fact)]);
            }
        }
        foreach (array_keys($this->links) as $key) {
            if (str_starts_with($key, implode(':', [$organizationId, $projectId, $sessionId]).':')) {
                unset($this->links[$key]);
            }
        }
        foreach (array_keys($this->understanding) as $key) {
            if (str_starts_with($key, implode(':', [$organizationId, $projectId, $sessionId]).':')) {
                $this->understanding[$key]['is_current'] = false;
            }
        }
        foreach (array_keys($this->technologyPlanningHistory) as $key) {
            if (str_starts_with($key, implode(':', [$organizationId, $projectId, $sessionId]).':')) {
                $this->technologyPlanningHistory[$key]['is_current'] = false;
            }
        }
        foreach (array_keys($this->completenessHistory) as $key) {
            if (str_starts_with($key, implode(':', [$organizationId, $projectId, $sessionId]).':')) {
                $this->completenessHistory[$key]['is_current'] = false;
            }
        }
    }

    public function removeProjection(string $factId): void
    {
        foreach ($this->projection as $key => $projectedId) {
            if ($projectedId === $factId) {
                unset($this->projection[$key]);
            }
        }
    }

    private function latestProjectionKey(array $history, string $scope, string $sourceVersion, string $inputFingerprint): ?string
    {
        $prefix = implode(':', [$scope, $sourceVersion, $inputFingerprint]).':';
        $latest = null;
        foreach ($history as $key => $run) {
            if (str_starts_with($key, $prefix)) {
                $latest = $key;
            }
        }

        return $latest;
    }

    private function factById(object $scope, string $factId): Fact
    {
        foreach ($this->facts as $fact) {
            if ($fact->id === $factId && $this->scope($fact) === $this->scope($scope)) {
                return $fact;
            }
        }

        throw new InvalidArgumentException('Fact is outside the requested scope.');
    }

    private function assertSameScope(object $left, object $right): void
    {
        if ($this->scope($left) !== $this->scope($right) || $left->sourceVersion !== $right->sourceVersion) {
            throw new InvalidArgumentException('Records are outside the requested scope.');
        }
    }

    private function recordKey(object $record): string
    {
        return implode(':', [...$this->scope($record), $record->sourceVersion, $record->id]);
    }

    private function logicalKey(Fact $fact): string
    {
        return implode(':', [...$this->scope($fact), $fact->entityId, $fact->type]);
    }

    private function scope(object $record): array
    {
        return [$record->organizationId, $record->projectId, $record->sessionId];
    }

    private function understandingSnapshotToken(int $organizationId, int $projectId, int $sessionId): string
    {
        $facts = $this->currentFacts($organizationId, $projectId, $sessionId);
        $factRecords = [];
        $bindings = [];
        $evidence = [];
        $sourceVersions = [];
        $entities = [];
        foreach ($this->entities as $entity) {
            if ($this->scope($entity) !== [$organizationId, $projectId, $sessionId]) {
                continue;
            }
            $entities[] = [
                'id' => $entity->id,
                'stable_key' => $entity->stableKey,
                'source_version' => $entity->sourceVersion,
                'content_hash' => hash('sha256', json_encode(get_object_vars($entity), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
            ];
            $sourceVersions[] = $entity->sourceVersion;
        }
        foreach ($facts as $fact) {
            $factRecords[] = [
                'id' => $fact->id,
                'stable_key' => $fact->id,
                'version' => $fact->version,
                'projection_version' => $fact->version,
                'status' => $fact->status,
                'value_hash' => hash('sha256', json_encode($fact->value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
                'semantic_hash' => hash('sha256', json_encode(get_object_vars($fact), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
            ];
            $sourceVersions[] = $fact->sourceVersion;
            foreach ($fact->evidenceIds as $evidenceId) {
                foreach ($this->evidence as $item) {
                    if ($item->id !== $evidenceId || $this->scope($item) !== [$organizationId, $projectId, $sessionId]) {
                        continue;
                    }
                    $bindings[] = [
                        'fact_id' => $fact->id,
                        'evidence_id' => $item->id,
                        'source_version' => $fact->sourceVersion,
                        'evidence_source_version' => $item->sourceVersion,
                        'evidence_invalidation_version' => 1,
                    ];
                    $evidence[$item->id] = [
                        'id' => $item->id,
                        'source_version' => $item->sourceVersion,
                        'invalidation_version' => 1,
                        'locator_hash' => hash('sha256', json_encode(get_object_vars($item), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
                    ];
                    $sourceVersions[] = $item->sourceVersion;
                }
            }
        }
        $decisions = [];
        foreach ($this->decisions as $decision) {
            if ($this->scope($decision) === [$organizationId, $projectId, $sessionId]) {
                $decisions[] = [
                    'id' => $decision->id,
                    'stable_key' => $decision->id,
                    'selected_fact_id' => $decision->selectedFactId,
                    'version' => $decision->version,
                ];
            }
        }

        return ProjectUnderstandingInputFingerprint::fromExactState([
            'scope' => compact('organizationId', 'projectId', 'sessionId'),
            'source_versions' => array_values(array_unique($sourceVersions)),
            'entities' => $entities,
            'facts' => $factRecords,
            'bindings' => $bindings,
            'evidence' => array_values($evidence),
            'decisions' => $decisions,
        ]);
    }
}
