<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackage;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;

final readonly class EloquentProjectModelRepository implements ProjectModelRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function saveSourceModel(array $entities, array $facts, array $evidence, array $conflicts = []): void
    {
        $modelRecords = [...$entities, ...$facts, ...$conflicts];
        $scope = $modelRecords[0] ?? null;
        if ($scope !== null && ! is_object($scope)) {
            throw new InvalidArgumentException('Project source model contains an invalid record.');
        }
        foreach ($modelRecords as $record) {
            if (! is_object($record) || $scope === null
                || [$record->organizationId, $record->projectId, $record->sessionId, $record->sourceVersion]
                    !== [$scope->organizationId, $scope->projectId, $scope->sessionId, $scope->sourceVersion]) {
                throw new InvalidArgumentException('Project source model contains a cross-scope record.');
            }
        }
        foreach ($evidence as $item) {
            if (! $item instanceof Evidence) {
                throw new InvalidArgumentException('Project model evidence batch is invalid.');
            }
            if ($scope !== null && [$item->organizationId, $item->projectId, $item->sessionId]
                !== [$scope->organizationId, $scope->projectId, $scope->sessionId]) {
                throw new InvalidArgumentException('Project source model evidence is outside the requested scope.');
            }
        }
        $availableEvidence = [];
        foreach ($evidence as $item) {
            $availableEvidence[$item->organizationId.':'.$item->projectId.':'.$item->sessionId.':'.$item->id] = true;
        }
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Project model fact batch is invalid.');
            }
            foreach ($fact->evidenceIds as $evidenceId) {
                if (! isset($availableEvidence[$fact->organizationId.':'.$fact->projectId.':'.$fact->sessionId.':'.$evidenceId])) {
                    throw new InvalidArgumentException('Project model fact references evidence outside the atomic source model.');
                }
            }
        }

        $this->database->connection()->transaction(function () use ($entities, $facts, $conflicts, $scope): void {
            if ($scope !== null) {
                $this->lockUnderstandingScope($scope->organizationId, $scope->projectId, $scope->sessionId);
            }
            $this->appendEntities($entities);
            $this->appendFacts($facts);
            $this->appendConflicts($conflicts);
        }, 3);
    }

    public function applyDecision(Decision $decision, Fact $selectedFact): void
    {
        ProjectModelInvariant::sameScope($decision, $selectedFact);
        if ($decision->selectedFactId !== $selectedFact->id) {
            throw new InvalidArgumentException('Project model decision does not select the supplied fact.');
        }
        $this->database->connection()->transaction(function () use ($decision, $selectedFact): void {
            $this->lockUnderstandingScope($decision->organizationId, $decision->projectId, $decision->sessionId);
            $this->appendFacts([$selectedFact]);
            $this->appendDecisions([$decision]);
            $this->database->table('estimate_generation_project_understanding_runs')
                ->where('organization_id', $decision->organizationId)
                ->where('project_id', $decision->projectId)
                ->where('session_id', $decision->sessionId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'invalidated_at' => now()]);
            $this->database->table('estimate_generation_project_model_cross_document_links')
                ->where('organization_id', $decision->organizationId)
                ->where('project_id', $decision->projectId)
                ->where('session_id', $decision->sessionId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'invalidated_at' => now()]);
            $this->database->table('estimate_generation_technology_planning_runs')
                ->where('organization_id', $decision->organizationId)
                ->where('project_id', $decision->projectId)
                ->where('session_id', $decision->sessionId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'invalidated_at' => now()]);
            $this->database->table('estimate_generation_completeness_runs')
                ->where('organization_id', $decision->organizationId)
                ->where('project_id', $decision->projectId)
                ->where('session_id', $decision->sessionId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'invalidated_at' => now()]);
        }, 3);
    }

    public function applyTechnologyDecision(
        Decision $decision,
        Fact $selectedFact,
        string $inputFingerprint,
        int $planningRunId,
    ): bool {
        return $this->database->connection()->transaction(function () use (
            $decision,
            $selectedFact,
            $inputFingerprint,
            $planningRunId,
        ): bool {
            $this->lockUnderstandingScope($decision->organizationId, $decision->projectId, $decision->sessionId);
            $run = $this->database->table('estimate_generation_technology_planning_runs')
                ->where('id', $planningRunId)
                ->where('organization_id', $decision->organizationId)
                ->where('project_id', $decision->projectId)
                ->where('session_id', $decision->sessionId)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first(['id', 'input_fingerprint']);
            $capture = $this->snapshotForPlanning(
                $decision->organizationId,
                $decision->projectId,
                $decision->sessionId,
                10001,
            );
            if ($run === null || ! hash_equals($inputFingerprint, (string) $run->input_fingerprint)
                || ! hash_equals($inputFingerprint, $capture['token'])) {
                return false;
            }
            $this->applyDecision($decision, $selectedFact);

            return true;
        }, 3);
    }

    public function applyCompletenessExclusionDecision(
        Decision $decision,
        Fact $selectedFact,
        string $inputFingerprint,
        int $completenessRunId,
    ): bool {
        ProjectModelInvariant::sameScope($decision, $selectedFact);
        if ($decision->selectedFactId !== $selectedFact->id) {
            throw new InvalidArgumentException('Completeness exclusion decision does not select the supplied fact.');
        }

        return $this->database->connection()->transaction(function () use (
            $decision,
            $selectedFact,
            $inputFingerprint,
            $completenessRunId,
        ): bool {
            $this->lockUnderstandingScope($decision->organizationId, $decision->projectId, $decision->sessionId);
            $run = $this->database->table('estimate_generation_completeness_runs')
                ->where('id', $completenessRunId)
                ->where('organization_id', $decision->organizationId)
                ->where('project_id', $decision->projectId)
                ->where('session_id', $decision->sessionId)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first(['id', 'input_fingerprint']);
            $capture = $this->snapshotForPlanning(
                $decision->organizationId,
                $decision->projectId,
                $decision->sessionId,
                10001,
            );
            if ($run === null || ! hash_equals($inputFingerprint, (string) $run->input_fingerprint)
                || ! hash_equals($inputFingerprint, $capture['token'])) {
                return false;
            }
            $this->applyDecision($decision, $selectedFact);

            return true;
        }, 3);
    }

    public function snapshot(int $organizationId, int $projectId, int $sessionId, ?int $factLimit = null): ProjectModelSnapshot
    {
        $facts = $this->currentFacts($organizationId, $projectId, $sessionId, limit: $factLimit);
        if ($facts === []) {
            return new ProjectModelSnapshot([], [], [], $this->currentConflicts($organizationId, $projectId, $sessionId));
        }
        $entityIds = array_values(array_unique(array_map(static fn (Fact $fact): string => $fact->entityId, $facts)));
        $entities = [];
        foreach ($this->database->table('estimate_generation_project_model_entities')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->whereIn('stable_key', $entityIds)
            ->orderBy('stable_key')->orderByDesc('id')->get() as $row) {
            if (isset($entities[(string) $row->stable_key])) {
                continue;
            }
            $attributes = $this->decode($row->payload);
            unset($attributes['kind'], $attributes['key']);
            $entities[(string) $row->stable_key] = new Entity(
                (string) $row->stable_key,
                (int) $row->organization_id,
                (int) $row->project_id,
                (int) $row->session_id,
                (string) $row->source_version,
                (string) $row->entity_kind,
                (string) $row->stable_key,
                $attributes,
            );
        }

        $evidenceIds = array_values(array_unique(array_merge(...array_map(
            static fn (Fact $fact): array => $fact->evidenceIds,
            $facts,
        ))));
        $numericIds = array_map(static fn (string $id): int => (int) substr($id, strlen('evidence:')), $evidenceIds);
        $evidence = [];
        if ($numericIds !== []) {
            foreach ($this->database->table('estimate_generation_evidence')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->whereNull('invalidated_at')
                ->whereIn('id', $numericIds)->orderBy('id')->get() as $row) {
                $locator = $this->decode($row->locator);
                $page = $locator['page'] ?? $locator['unit_index'] ?? null;
                $region = is_array($locator['region'] ?? null) ? $locator['region'] : null;
                if ($region === null && is_array($locator['bbox'] ?? null) && count($locator['bbox']) === 4) {
                    $region = [
                        'x' => $locator['bbox'][0],
                        'y' => $locator['bbox'][1],
                        'width' => $locator['bbox'][2] - $locator['bbox'][0],
                        'height' => $locator['bbox'][3] - $locator['bbox'][1],
                    ];
                }
                $nativeReference = $locator['native_reference'] ?? $locator['handle']
                    ?? $locator['element_key'] ?? $locator['source_key'] ?? null;
                $sourceArtifactId = $locator['source_artifact_id'] ?? $row->source_ref;
                $evidence[] = new Evidence(
                    'evidence:'.$row->id,
                    (int) $row->organization_id,
                    (int) $row->project_id,
                    (int) $row->session_id,
                    (string) $row->source_version,
                    (string) $sourceArtifactId,
                    (string) $row->source_type,
                    is_int($page) && $page > 0 ? $page : null,
                    $region,
                    is_string($nativeReference) && trim($nativeReference) !== '' ? $nativeReference : null,
                );
            }
        }

        return new ProjectModelSnapshot(
            array_values($entities),
            $facts,
            $evidence,
            $this->currentConflicts($organizationId, $projectId, $sessionId),
        );
    }

    public function snapshotForUnderstanding(int $organizationId, int $projectId, int $sessionId, int $factLimit): array
    {
        return $this->database->connection()->transaction(function () use ($organizationId, $projectId, $sessionId, $factLimit): array {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);

            return [
                'snapshot' => $this->snapshot($organizationId, $projectId, $sessionId, $factLimit),
                'token' => $this->understandingSnapshotToken($organizationId, $projectId, $sessionId),
            ];
        }, 3);
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
        if (min(
            $organizationId,
            $projectId,
            $sessionId,
            $maxFacts,
            $maxEvidenceItems,
            $maxEvidencePerFact,
            $maxEvidencePayloadBytes,
            $maxEvidenceBytesPerItem,
        ) < 1) {
            throw new InvalidArgumentException('Project understanding preflight scope or budget is invalid.');
        }
        $row = $this->database->connection()->selectOne(<<<'SQL'
WITH scoped_facts AS (
    SELECT fact.id, fact.source_version
    FROM estimate_generation_project_model_fact_projections projection
    JOIN estimate_generation_project_model_assertions fact ON fact.id = projection.fact_id
    WHERE projection.organization_id = ? AND projection.project_id = ? AND projection.session_id = ?
      AND projection.is_current
), scoped_bindings AS (
    SELECT binding.fact_id, evidence.id AS evidence_id,
           octet_length(jsonb_build_object(
               'id', 'evidence:' || evidence.id,
               'source_version', evidence.source_version,
               'source_artifact_id', evidence.source_ref,
               'source_type', evidence.source_type,
               'locator', evidence.locator
           )::text) AS payload_bytes
    FROM scoped_facts fact
    JOIN estimate_generation_project_model_fact_evidence binding ON binding.fact_id = fact.id
      AND binding.organization_id = ? AND binding.project_id = ? AND binding.session_id = ?
      AND binding.source_version = fact.source_version
    JOIN estimate_generation_evidence evidence ON evidence.id = binding.evidence_id
      AND evidence.organization_id = binding.organization_id
      AND evidence.project_id = binding.project_id AND evidence.session_id = binding.session_id
      AND evidence.source_version = binding.evidence_source_version
      AND evidence.invalidation_version = binding.evidence_invalidation_version
      AND evidence.invalidated_at IS NULL
), evidence_set AS (
    SELECT evidence_id, max(payload_bytes) AS payload_bytes FROM scoped_bindings GROUP BY evidence_id
), binding_counts AS (
    SELECT fact_id, count(*) AS binding_count FROM scoped_bindings GROUP BY fact_id
)
SELECT count(DISTINCT scoped_facts.id) AS fact_count,
       min(scoped_facts.source_version) AS source_version,
       count(DISTINCT scoped_facts.source_version) AS source_version_count,
       COALESCE((SELECT count(*) FROM evidence_set), 0) AS evidence_count,
       COALESCE((SELECT max(binding_count) FROM binding_counts), 0) AS max_evidence_per_fact,
       COALESCE((SELECT sum(payload_bytes) FROM evidence_set), 0) AS total_payload_bytes,
       COALESCE((SELECT max(payload_bytes) FROM evidence_set), 0) AS max_payload_bytes
FROM scoped_facts
SQL, [
            $organizationId, $projectId, $sessionId,
            $organizationId, $projectId, $sessionId,
        ]);
        $withinBudget = (int) $row->fact_count <= $maxFacts
            && (int) $row->evidence_count <= $maxEvidenceItems
            && (int) $row->max_evidence_per_fact <= $maxEvidencePerFact
            && (int) $row->total_payload_bytes <= $maxEvidencePayloadBytes
            && (int) $row->max_payload_bytes <= $maxEvidenceBytesPerItem;

        return [
            'within_budget' => $withinBudget,
            'source_version' => is_string($row->source_version) ? $row->source_version : null,
            'fact_count' => (int) $row->fact_count,
            'evidence_count' => (int) $row->evidence_count,
            'max_evidence_per_fact' => (int) $row->max_evidence_per_fact,
            'total_payload_bytes' => (int) $row->total_payload_bytes,
            'max_payload_bytes' => (int) $row->max_payload_bytes,
        ];
    }

    private function appendEntities(array $entities, int $chunkSize = 500): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($entities, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                foreach ($chunk as $entity) {
                    if (! $entity instanceof Entity) {
                        throw new InvalidArgumentException('Project model entity batch is invalid.');
                    }
                    $projectionScopeId = $this->projectionScopeId($entity);
                    $payload = [
                        'kind' => $entity->type,
                        'key' => $entity->stableKey,
                        ...$entity->attributes,
                    ];
                    if (in_array($entity->type, ['material', 'equipment'], true)
                        && ($payload['properties'] ?? null) === []) {
                        $payload['properties'] = (object) [];
                    }
                    $serializedPayload = $this->json($payload);
                    $this->database->table('estimate_generation_project_model_entities')->insertOrIgnore([
                        'building_model_id' => $projectionScopeId,
                        'organization_id' => $entity->organizationId,
                        'project_id' => $entity->projectId,
                        'session_id' => $entity->sessionId,
                        'source_version' => $entity->sourceVersion,
                        'stable_key' => $entity->stableKey,
                        'entity_kind' => $entity->type,
                        'payload' => $serializedPayload,
                        'confidence' => null,
                        'created_at' => now(),
                    ]);
                    $stored = $this->database->table('estimate_generation_project_model_entities')
                        ->where('building_model_id', $projectionScopeId)
                        ->where('stable_key', $entity->stableKey)
                        ->first(['entity_kind', 'payload']);
                    $comparisonPayload = $payload;
                    if (is_object($comparisonPayload['properties'] ?? null)
                        && get_object_vars($comparisonPayload['properties']) === []) {
                        $comparisonPayload['properties'] = [];
                    }
                    if ($stored === null || (string) $stored->entity_kind !== $entity->type
                        || (! hash_equals(
                            DerivedQuantityIdentity::canonicalJson($comparisonPayload),
                            DerivedQuantityIdentity::canonicalJson($this->decodedJson($stored->payload)),
                        ) && ! $this->isCompatibleLegacyEntityPayload(
                            $entity->type,
                            $this->decodedJson($stored->payload),
                            $comparisonPayload,
                        ))) {
                        throw new InvalidArgumentException('project_model_entity_exact_identity_collision');
                    }
                }
            }, 3);
        }
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $canonical */
    private function isCompatibleLegacyEntityPayload(string $type, array $stored, array $canonical): bool
    {
        if (($stored['kind'] ?? null) !== $type
            || ($stored['key'] ?? null) !== ($canonical['key'] ?? null)
            || ($canonical['kind'] ?? null) !== $type) {
            return false;
        }

        if ($type === 'room') {
            $legacy = $stored;
            unset($legacy['area_m2']);
            $expected = $canonical;
            unset($expected['semantic_type']);

            return hash_equals(
                DerivedQuantityIdentity::canonicalJson($legacy),
                DerivedQuantityIdentity::canonicalJson($expected),
            ) && ($canonical['semantic_type'] ?? null) === 'room';
        }

        if ($type === 'dimension') {
            $legacy = $stored;
            unset($legacy['value'], $legacy['unit']);
            $expected = $canonical;
            unset($expected['measurement_kind']);

            return hash_equals(
                DerivedQuantityIdentity::canonicalJson($legacy),
                DerivedQuantityIdentity::canonicalJson($expected),
            )
                && is_string($canonical['measurement_kind'] ?? null)
                && array_key_exists('value', $stored)
                && array_key_exists('unit', $stored);
        }

        return false;
    }

    private function appendFacts(array $facts, int $chunkSize = 500): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($facts, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                $evidenceRows = $this->evidenceRowsForFacts($chunk);
                foreach ($chunk as $fact) {
                    if (! $fact instanceof Fact) {
                        throw new InvalidArgumentException('Project model fact batch is invalid.');
                    }
                    $projectionScopeId = $this->projectionScopeId($fact);
                    $entity = $this->entityRow($fact);
                    $payload = ['source' => $this->legacySource($fact->origin), 'value' => $fact->value];
                    if ($fact->unit !== null) {
                        $payload['unit'] = $fact->unit;
                    }
                    $this->database->table('estimate_generation_project_model_assertions')->insertOrIgnore([
                        'building_model_id' => $projectionScopeId,
                        'organization_id' => $fact->organizationId,
                        'project_id' => $fact->projectId,
                        'session_id' => $fact->sessionId,
                        'source_version' => $fact->sourceVersion,
                        'stable_key' => $fact->id,
                        'entity_id' => (int) $entity->id,
                        'assertion_type' => $fact->type,
                        'payload' => $this->json($payload),
                        'confidence' => $fact->confidence,
                        'fact_origin' => $fact->origin,
                        'fact_status' => $fact->status,
                        'fact_version' => $fact->version,
                        'supersedes_assertion_id' => $fact->supersedesFactId === null
                            ? null
                            : $this->factDatabaseId($fact, $fact->supersedesFactId),
                        'fact_value' => $this->json(['value' => $fact->value]),
                        'fact_unit' => $fact->unit,
                        'created_at' => now(),
                    ]);
                    $factDatabaseId = $this->factDatabaseId($fact, $fact->id);
                    foreach ($fact->evidenceIds as $evidenceId) {
                        $evidence = $evidenceRows[$this->evidenceMapKey($fact, $evidenceId)];
                        $this->database->table('estimate_generation_project_model_fact_evidence')->insertOrIgnore([
                            'fact_id' => $factDatabaseId,
                            'evidence_id' => (int) $evidence->id,
                            'organization_id' => $fact->organizationId,
                            'project_id' => $fact->projectId,
                            'session_id' => $fact->sessionId,
                            'source_version' => $fact->sourceVersion,
                            'evidence_source_version' => (string) $evidence->source_version,
                            'evidence_invalidation_version' => (int) $evidence->invalidation_version,
                            'created_at' => now(),
                        ]);
                    }
                    if (in_array($fact->status, ['confirmed', 'conflicted', 'unresolved'], true)) {
                        $this->projectCurrentFact($fact, $projectionScopeId, (int) $entity->id);
                    }
                }
            }, 3);
        }
    }

    private function appendConflicts(array $conflicts, int $chunkSize = 200): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($conflicts, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                foreach ($chunk as $conflict) {
                    if (! $conflict instanceof Conflict) {
                        throw new InvalidArgumentException('Project model conflict batch is invalid.');
                    }
                    $this->database->table('estimate_generation_project_model_conflicts')->insertOrIgnore([
                        'organization_id' => $conflict->organizationId,
                        'project_id' => $conflict->projectId,
                        'session_id' => $conflict->sessionId,
                        'source_version' => $conflict->sourceVersion,
                        'stable_key' => $conflict->id,
                        'reason' => $conflict->reason,
                        'status' => $conflict->status,
                        'conflict_version' => $conflict->version,
                        'created_at' => now(),
                    ]);
                    $conflictId = $this->database->table('estimate_generation_project_model_conflicts')
                        ->where('organization_id', $conflict->organizationId)
                        ->where('project_id', $conflict->projectId)
                        ->where('session_id', $conflict->sessionId)
                        ->where('source_version', $conflict->sourceVersion)
                        ->where('stable_key', $conflict->id)
                        ->where('conflict_version', $conflict->version)
                        ->value('id');
                    foreach ($conflict->facts as $fact) {
                        $this->database->table('estimate_generation_project_model_conflict_facts')->insertOrIgnore([
                            'conflict_id' => (int) $conflictId,
                            'fact_id' => $this->factDatabaseId($fact, $fact->id),
                            'organization_id' => $conflict->organizationId,
                            'project_id' => $conflict->projectId,
                            'session_id' => $conflict->sessionId,
                            'source_version' => $conflict->sourceVersion,
                        ]);
                    }
                }
            }, 3);
        }
    }

    private function appendDecisions(array $decisions, int $chunkSize = 200): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($decisions, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                foreach ($chunk as $decision) {
                    if (! $decision instanceof Decision || $decision->selectedFactId === null
                        || ($decision->actorType === 'user'
                            && (! ctype_digit($decision->actorId) || (int) $decision->actorId <= 0))) {
                        throw new InvalidArgumentException('Project model decision batch is invalid.');
                    }
                    $projectionScopeId = $this->projectionScopeId($decision);
                    $selectedFact = $this->factRow($decision, $decision->selectedFactId);
                    if ($decision->actorType === 'system'
                        && ((string) $selectedFact->fact_status !== 'confirmed'
                            || in_array((string) $selectedFact->fact_origin, [
                                'ai_technology_recommendation',
                                'unresolved',
                            ], true))) {
                        throw new InvalidArgumentException('System decision cannot confirm an unresolved or recommended fact.');
                    }
                    $value = $this->decode($selectedFact->fact_value ?? null);
                    $this->database->table('estimate_generation_project_model_corrections')->insertOrIgnore([
                        'building_model_id' => $projectionScopeId,
                        'organization_id' => $decision->organizationId,
                        'project_id' => $decision->projectId,
                        'session_id' => $decision->sessionId,
                        'source_version' => $decision->sourceVersion,
                        'stable_key' => $decision->id,
                        'assertion_id' => (int) $selectedFact->id,
                        'correction_type' => $decision->actorType === 'user' ? 'manual' : 'source_reconciliation',
                        'payload' => $this->json(['canonical_value' => $value]),
                        'reason' => $decision->reason,
                        'actor_id' => $decision->actorType === 'user' ? (int) $decision->actorId : null,
                        'decision_actor_type' => $decision->actorType,
                        'system_actor_key' => $decision->actorType === 'system' ? $decision->actorId : null,
                        'decision_version' => $decision->version,
                        'target_conflict_key' => $decision->targetType === 'conflict' ? $decision->targetId : null,
                        'selected_fact_stable_key' => $decision->selectedFactId,
                        'evidence_lineage' => $this->json($decision->evidenceIds),
                        'created_at' => now(),
                    ]);
                }
            }, 3);
        }
    }

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void
    {
        $this->assertChunkSize($chunkSize);
        foreach ($quantities as $quantity) {
            if (! $quantity instanceof DerivedQuantity) {
                throw new InvalidArgumentException('Derived quantity batch is invalid.');
            }
            DerivedQuantity::assertRoundingScale($quantity->value, $quantity->status, $quantity->roundingScale);
        }
        foreach (array_chunk($quantities, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                $this->assertDerivedOperands($chunk);
                foreach ($chunk as $quantity) {
                    if (! $quantity instanceof DerivedQuantity) {
                        throw new InvalidArgumentException('Derived quantity batch is invalid.');
                    }
                    if ($quantity->value !== null
                        && ! hash_equals($quantity->value, DecimalValue::canonical($quantity->value))) {
                        throw new InvalidArgumentException('Derived quantity value is not storage-canonical.');
                    }
                    foreach ($quantity->operands as $operand) {
                        if (! hash_equals($operand['value'], DecimalValue::canonical($operand['value']))) {
                            throw new InvalidArgumentException('Derived quantity operand is not storage-canonical.');
                        }
                    }
                    if ($quantity->exactIdentity === null
                        || ! hash_equals($quantity->exactIdentity, DerivedQuantityIdentity::for($quantity))
                        || ! hash_equals($quantity->id, 'quantityv:'.$quantity->exactIdentity)) {
                        throw new InvalidArgumentException('Derived quantity exact identity does not match its content.');
                    }
                    $this->lockUnderstandingScope(
                        $quantity->organizationId,
                        $quantity->projectId,
                        $quantity->sessionId,
                    );
                    $scopeQuery = $this->database->table('estimate_generation_project_model_derived_quantities')
                        ->where('organization_id', $quantity->organizationId)
                        ->where('project_id', $quantity->projectId)
                        ->where('session_id', $quantity->sessionId)
                        ->where('source_version', $quantity->sourceVersion);
                    $existing = (clone $scopeQuery)->where('stable_key', $quantity->id)->first();
                    if ($existing !== null) {
                        $this->assertDerivedQuantityReplay($quantity, $existing);
                        $this->switchDerivedQuantityProjection($quantity, (int) $existing->id);

                        continue;
                    }
                    $quantityId = $this->database->table('estimate_generation_project_model_derived_quantities')->insertGetId([
                        'organization_id' => $quantity->organizationId,
                        'project_id' => $quantity->projectId,
                        'session_id' => $quantity->sessionId,
                        'source_version' => $quantity->sourceVersion,
                        'stable_key' => $quantity->id,
                        'logical_key' => $quantity->logicalId,
                        'exact_identity' => $quantity->exactIdentity,
                        'entity_stable_key' => $quantity->entityId,
                        'formula' => $quantity->formula,
                        'formula_identity' => $quantity->formulaIdentity,
                        'formula_version' => $quantity->formulaVersion,
                        'value' => $quantity->value,
                        'unit' => $quantity->unit,
                        'rounding_mode' => $quantity->roundingMode,
                        'rounding_scale' => $quantity->roundingScale,
                        'rounding_boundary' => $quantity->roundingBoundary,
                        'unit_compatibility' => $quantity->unitCompatibility,
                        'snapshot_identity' => $this->json($quantity->snapshotIdentity),
                        'technology_decision_id' => $quantity->technologyDecisionId,
                        'identity_status' => 'exact',
                        'status' => $quantity->status,
                        'evidence_lineage' => $this->json($quantity->evidenceIds),
                        'unresolved_inputs' => $this->json($quantity->unresolvedInputs),
                        'created_at' => now(),
                    ]);
                    foreach ($quantity->operands as $ordinal => $operand) {
                        $this->database->table('estimate_generation_project_model_derived_operands')->insert([
                            'derived_quantity_id' => (int) $quantityId,
                            'fact_id' => $this->factDatabaseId($quantity, $operand['fact_id']),
                            'organization_id' => $quantity->organizationId,
                            'project_id' => $quantity->projectId,
                            'session_id' => $quantity->sessionId,
                            'source_version' => $quantity->sourceVersion,
                            'operand_ordinal' => $ordinal,
                            'operand_snapshot' => $this->json($operand),
                        ]);
                    }
                    $this->switchDerivedQuantityProjection($quantity, (int) $quantityId);
                }
            }, 3);
        }
    }

    public function replaceDerivedQuantityProjection(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        array $quantities,
        array $inactiveLogicalIds,
    ): void {
        $this->database->connection()->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $quantities,
            $inactiveLogicalIds,
        ): void {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $inactive = array_values(array_unique(array_filter($inactiveLogicalIds, 'is_string')));
            if ($inactive !== []) {
                $this->database->table('estimate_generation_project_model_derived_quantity_projections')
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->where('session_id', $sessionId)
                    ->where('source_version', $sourceVersion)
                    ->whereIn('logical_key', $inactive)
                    ->delete();
            }
            $this->appendDerivedQuantities($quantities, 200);
            foreach ($quantities as $quantity) {
                if (! $quantity instanceof DerivedQuantity
                    || [$quantity->organizationId, $quantity->projectId, $quantity->sessionId, $quantity->sourceVersion]
                        !== [$organizationId, $projectId, $sessionId, $sourceVersion]) {
                    throw new InvalidArgumentException('Derived quantity projection scope is invalid.');
                }
            }
        }, 3);
    }

    public function currentDerivedQuantities(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        int $limit = 200,
    ): array {
        $this->assertReadLimit($limit);
        $rows = $this->database->table('estimate_generation_project_model_derived_quantity_projections as projection')
            ->join('estimate_generation_project_model_derived_quantities as quantity', 'quantity.id', '=', 'projection.derived_quantity_id')
            ->where('projection.organization_id', $organizationId)
            ->where('projection.project_id', $projectId)
            ->where('projection.session_id', $sessionId)
            ->where('projection.source_version', $sourceVersion)
            ->orderBy('projection.logical_key')
            ->limit($limit)
            ->get('quantity.*');

        return $rows->map(fn (object $row): DerivedQuantity => $this->derivedQuantityFromRow($row))->all();
    }

    public function replaceDerivedQuantityFormulaProjectionSet(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $formulaVersion,
        array $quantities,
    ): void {
        $this->database->connection()->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $formulaVersion,
            $quantities,
        ): void {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $quantityIds = $this->database->table('estimate_generation_project_model_derived_quantities')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('formula_version', $formulaVersion)
                ->pluck('id');
            if ($quantityIds->isNotEmpty()) {
                $this->database->table('estimate_generation_project_model_derived_quantity_projections')
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->where('session_id', $sessionId)
                    ->whereIn('derived_quantity_id', $quantityIds)
                    ->delete();
            }
            foreach ($quantities as $quantity) {
                if (! $quantity instanceof DerivedQuantity
                    || [$quantity->organizationId, $quantity->projectId, $quantity->sessionId, $quantity->formulaVersion]
                        !== [$organizationId, $projectId, $sessionId, $formulaVersion]) {
                    throw new InvalidArgumentException('Derived quantity formula projection scope is invalid.');
                }
            }
            $this->appendDerivedQuantities($quantities, 200);
        }, 3);
    }

    public function currentDerivedQuantitiesForFormulaVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $formulaVersion,
        int $limit = 200,
    ): array {
        $this->assertReadLimit($limit);
        $rows = $this->database->table('estimate_generation_project_model_derived_quantity_projections as projection')
            ->join('estimate_generation_project_model_derived_quantities as quantity', 'quantity.id', '=', 'projection.derived_quantity_id')
            ->where('projection.organization_id', $organizationId)
            ->where('projection.project_id', $projectId)
            ->where('projection.session_id', $sessionId)
            ->where('quantity.formula_version', $formulaVersion)
            ->orderBy('projection.logical_key')
            ->limit($limit)
            ->get('quantity.*');

        return $rows->map(fn (object $row): DerivedQuantity => $this->derivedQuantityFromRow($row))->all();
    }

    public function currentDerivedQuantityLogicalIdsByFormulaVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $formulaVersion,
    ): array {
        return $this->database->table('estimate_generation_project_model_derived_quantity_projections as projection')
            ->join('estimate_generation_project_model_derived_quantities as quantity', 'quantity.id', '=', 'projection.derived_quantity_id')
            ->where('projection.organization_id', $organizationId)
            ->where('projection.project_id', $projectId)
            ->where('projection.session_id', $sessionId)
            ->where('projection.source_version', $sourceVersion)
            ->where('quantity.formula_version', $formulaVersion)
            ->orderBy('projection.logical_key')
            ->pluck('projection.logical_key')
            ->map(static fn (mixed $logicalId): string => (string) $logicalId)
            ->all();
    }

    public function deactivateDerivedQuantityProjectionScope(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?string $sourceVersion = null,
    ): void {
        $this->database->connection()->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
        ): void {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $query = $this->database->table('estimate_generation_project_model_derived_quantity_projections')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId);
            if ($sourceVersion !== null) {
                $query->where('source_version', $sourceVersion);
            }
            $query->delete();
        }, 3);
    }

    public function derivedQuantityHistory(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $logicalId,
        int $limit = 200,
    ): array {
        $this->assertChunkSize($limit);
        $rows = $this->database->table('estimate_generation_project_model_derived_quantities')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', $sourceVersion)
            ->where('logical_key', $logicalId)
            ->where('identity_status', 'exact')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row): DerivedQuantity => $this->derivedQuantityFromRow($row))->all();
    }

    private function switchDerivedQuantityProjection(DerivedQuantity $quantity, int $quantityId): void
    {
        $this->database->table('estimate_generation_project_model_derived_quantity_projections')->updateOrInsert([
            'organization_id' => $quantity->organizationId,
            'project_id' => $quantity->projectId,
            'session_id' => $quantity->sessionId,
            'source_version' => $quantity->sourceVersion,
            'logical_key' => $quantity->logicalId,
        ], [
            'derived_quantity_id' => $quantityId,
            'exact_identity' => $quantity->exactIdentity,
            'updated_at' => now(),
        ]);
    }

    private function derivedQuantityFromRow(object $row): DerivedQuantity
    {
        $operands = $this->database->table('estimate_generation_project_model_derived_operands')
            ->where('derived_quantity_id', (int) $row->id)
            ->orderBy('operand_ordinal')
            ->pluck('operand_snapshot')
            ->map(fn (mixed $operand): mixed => $this->decodedJson($operand))
            ->all();

        return new DerivedQuantity(
            id: (string) $row->stable_key,
            organizationId: (int) $row->organization_id,
            projectId: (int) $row->project_id,
            sessionId: (int) $row->session_id,
            sourceVersion: (string) $row->source_version,
            entityId: (string) $row->entity_stable_key,
            formula: (string) $row->formula,
            operands: $operands,
            value: $row->value === null ? null : DecimalValue::canonical((string) $row->value),
            unit: (string) $row->unit,
            roundingMode: (string) $row->rounding_mode,
            roundingScale: (int) $row->rounding_scale,
            evidenceIds: $this->decodedJson($row->evidence_lineage),
            status: (string) $row->status,
            formulaIdentity: (string) $row->formula_identity,
            formulaVersion: (string) $row->formula_version,
            roundingBoundary: (string) $row->rounding_boundary,
            unitCompatibility: (string) $row->unit_compatibility,
            snapshotIdentity: $this->decodedJson($row->snapshot_identity),
            technologyDecisionId: $row->technology_decision_id === null ? null : (string) $row->technology_decision_id,
            logicalId: (string) $row->logical_key,
            exactIdentity: (string) $row->exact_identity,
        );
    }

    private function assertDerivedQuantityReplay(DerivedQuantity $quantity, object $existing): void
    {
        $storedOperands = $this->database->table('estimate_generation_project_model_derived_operands')
            ->where('derived_quantity_id', (int) $existing->id)
            ->orderBy('operand_ordinal')
            ->pluck('operand_snapshot')
            ->map(fn (mixed $operand): mixed => is_string($operand) ? json_decode($operand, true, 512, JSON_THROW_ON_ERROR) : $operand)
            ->all();
        $stored = [
            'logical_id' => (string) $existing->logical_key,
            'exact_identity' => (string) $existing->exact_identity,
            'entity_id' => (string) $existing->entity_stable_key,
            'formula' => (string) $existing->formula,
            'formula_identity' => (string) $existing->formula_identity,
            'formula_version' => (string) $existing->formula_version,
            'value' => $existing->value === null ? null : DecimalValue::canonical((string) $existing->value),
            'unit' => (string) $existing->unit,
            'rounding_mode' => (string) $existing->rounding_mode,
            'rounding_scale' => (int) $existing->rounding_scale,
            'rounding_boundary' => (string) $existing->rounding_boundary,
            'unit_compatibility' => (string) $existing->unit_compatibility,
            'snapshot_identity' => $this->decodedJson($existing->snapshot_identity),
            'technology_decision_id' => $existing->technology_decision_id,
            'status' => (string) $existing->status,
            'evidence_ids' => $this->decodedJson($existing->evidence_lineage),
            'unresolved_inputs' => $this->decodedJson($existing->unresolved_inputs),
            'operands' => $storedOperands,
        ];
        $expected = [
            'logical_id' => $quantity->logicalId,
            'exact_identity' => $quantity->exactIdentity,
            'entity_id' => $quantity->entityId,
            'formula' => $quantity->formula,
            'formula_identity' => $quantity->formulaIdentity,
            'formula_version' => $quantity->formulaVersion,
            'value' => $quantity->value,
            'unit' => $quantity->unit,
            'rounding_mode' => $quantity->roundingMode,
            'rounding_scale' => $quantity->roundingScale,
            'rounding_boundary' => $quantity->roundingBoundary,
            'unit_compatibility' => $quantity->unitCompatibility,
            'snapshot_identity' => $quantity->snapshotIdentity,
            'technology_decision_id' => $quantity->technologyDecisionId,
            'status' => $quantity->status,
            'evidence_ids' => $quantity->evidenceIds,
            'unresolved_inputs' => $quantity->unresolvedInputs,
            'operands' => $quantity->operands,
        ];
        if (! hash_equals(
            DerivedQuantityIdentity::canonicalJson($expected),
            DerivedQuantityIdentity::canonicalJson($stored),
        )) {
            throw new InvalidArgumentException('Derived quantity exact identity collision.');
        }
    }

    private function decodedJson(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
    }

    private function appendCrossDocumentLinks(array $links, int $chunkSize = 200): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($links, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                $evidenceRows = $this->evidenceRowsForLinks($chunk);
                $factIds = $this->factIdsForLinks($chunk);
                $linkRows = [];
                foreach ($chunk as $link) {
                    $linkRows[] = [
                        'organization_id' => $link['organization_id'],
                        'project_id' => $link['project_id'],
                        'session_id' => $link['session_id'],
                        'source_version' => $link['source_version'],
                        'stable_key' => $link['id'],
                        'left_fact_id' => $factIds[$this->linkFactMapKey($link, $link['left_fact_id'])],
                        'right_fact_id' => $factIds[$this->linkFactMapKey($link, $link['right_fact_id'])],
                        'strategy' => $link['strategy'],
                        'match_key' => $link['match_key'],
                        'reason' => $link['reason'],
                        'strategy_version' => $link['strategy_version'],
                        'operation_identity' => $link['operation_identity'],
                        'status' => $link['status'],
                        'candidate_fact_ids' => $this->json($link['candidate_fact_ids'] ?? [
                            $link['left_fact_id'],
                            $link['right_fact_id'],
                        ]),
                        'candidate_evidence_ids' => $this->json($link['candidate_evidence_ids'] ?? array_values(array_unique([
                            ...$link['evidence']['left'],
                            ...$link['evidence']['right'],
                        ]))),
                        'is_current' => true,
                        'created_at' => now(),
                    ];
                }
                $this->database->table('estimate_generation_project_model_cross_document_links')->insertOrIgnore($linkRows);
                $storedLinks = [];
                foreach ($this->database->table('estimate_generation_project_model_cross_document_links')
                    ->whereIn('operation_identity', array_column($chunk, 'operation_identity'))
                    ->get([
                        'id', 'organization_id', 'project_id', 'session_id', 'source_version', 'operation_identity',
                        'stable_key', 'left_fact_id', 'right_fact_id', 'strategy', 'match_key', 'reason', 'strategy_version',
                        'status', 'candidate_fact_ids', 'candidate_evidence_ids',
                    ]) as $row) {
                    $key = $row->organization_id.':'.$row->project_id.':'.$row->session_id.':'.$row->source_version.':'.$row->operation_identity;
                    $storedLinks[$key] = $row;
                }
                $evidenceLinks = [];
                foreach ($chunk as $index => $link) {
                    $stored = $storedLinks[$this->linkScopeKey($link).':'.$link['operation_identity']] ?? null;
                    if ($stored === null) {
                        throw new InvalidArgumentException('Cross-document link persistence failed.');
                    }
                    $expected = $linkRows[$index];
                    if ((string) $stored->stable_key !== $expected['stable_key']
                        || (int) $stored->left_fact_id !== $expected['left_fact_id']
                        || (int) $stored->right_fact_id !== $expected['right_fact_id']
                        || (string) $stored->strategy !== $expected['strategy']
                        || (string) $stored->match_key !== $expected['match_key']
                        || (string) $stored->reason !== $expected['reason']
                        || (int) $stored->strategy_version !== $expected['strategy_version']
                        || (string) $stored->status !== $expected['status']
                        || ! hash_equals($this->json($this->decode($stored->candidate_fact_ids)), $expected['candidate_fact_ids'])
                        || ! hash_equals($this->json($this->decode($stored->candidate_evidence_ids)), $expected['candidate_evidence_ids'])) {
                        throw new InvalidArgumentException('Cross-document link operation identity collision.');
                    }
                    $linkId = (int) $stored->id;
                    foreach (['left', 'right'] as $side) {
                        foreach ($link['evidence'][$side] as $evidenceId) {
                            $row = $evidenceRows[$this->linkEvidenceMapKey($link, $evidenceId)];
                            $evidenceLinks[] = [
                                'link_id' => $linkId,
                                'evidence_id' => (int) $row->id,
                                'organization_id' => $link['organization_id'],
                                'project_id' => $link['project_id'],
                                'session_id' => $link['session_id'],
                                'source_version' => $link['source_version'],
                                'side' => $side,
                            ];
                        }
                    }
                }
                foreach (array_chunk($evidenceLinks, 1000) as $evidenceChunk) {
                    $this->database->table('estimate_generation_project_model_cross_link_evidence')->insertOrIgnore($evidenceChunk);
                }
                $expectedEvidence = array_map(
                    static fn (array $row): string => $row['link_id'].':'.$row['side'].':'.$row['evidence_id'],
                    $evidenceLinks,
                );
                $actualEvidence = $this->database->table('estimate_generation_project_model_cross_link_evidence')
                    ->whereIn('link_id', array_map(static fn (object $row): int => (int) $row->id, $storedLinks))
                    ->get(['link_id', 'side', 'evidence_id'])
                    ->map(static fn (object $row): string => $row->link_id.':'.$row->side.':'.$row->evidence_id)
                    ->all();
                sort($expectedEvidence);
                sort($actualEvidence);
                if ($actualEvidence !== $expectedEvidence) {
                    throw new InvalidArgumentException('Cross-document link evidence collision.');
                }
            }, 3);
        }
    }

    public function currentFacts(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?string $entityId = null,
        ?int $limit = null,
    ): array {
        if ($organizationId <= 0 || $projectId <= 0 || $sessionId <= 0) {
            throw new InvalidArgumentException('Project model query scope is invalid.');
        }
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Project model query limit is invalid.');
        }
        $query = $this->database->table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'fact.entity_id')
            ->where('projection.organization_id', $organizationId)
            ->where('projection.project_id', $projectId)
            ->where('projection.session_id', $sessionId)
            ->where('projection.is_current', true);
        if ($entityId !== null) {
            ProjectModelInvariant::id($entityId, 'Entity');
            $query->where('entity.stable_key', $entityId);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }
        $rows = $query->orderBy('entity.stable_key')->orderBy('fact.assertion_type')->get([
            'fact.*', 'entity.stable_key as entity_stable_key',
        ]);
        $evidenceByFact = [];
        $factIds = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $supersededIds = $rows->pluck('supersedes_assertion_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $supersededKeys = $supersededIds === [] ? [] : $this->database
            ->table('estimate_generation_project_model_assertions')
            ->whereIn('id', $supersededIds)->pluck('stable_key', 'id')->all();
        if ($factIds !== []) {
            foreach ($this->database->table('estimate_generation_project_model_fact_evidence as binding')
                ->join('estimate_generation_evidence as evidence', function ($join): void {
                    $join->on('evidence.id', '=', 'binding.evidence_id')
                        ->on('evidence.organization_id', '=', 'binding.organization_id')
                        ->on('evidence.project_id', '=', 'binding.project_id')
                        ->on('evidence.session_id', '=', 'binding.session_id');
                })
                ->whereIn('binding.fact_id', $factIds)
                ->where('binding.organization_id', $organizationId)
                ->where('binding.project_id', $projectId)
                ->where('binding.session_id', $sessionId)
                ->whereNull('evidence.invalidated_at')
                ->whereColumn('evidence.source_version', 'binding.evidence_source_version')
                ->whereColumn('evidence.invalidation_version', 'binding.evidence_invalidation_version')
                ->orderBy('binding.fact_id')->orderBy('binding.evidence_id')
                ->get(['binding.fact_id', 'binding.evidence_id']) as $binding) {
                $evidenceByFact[(int) $binding->fact_id][] = 'evidence:'.$binding->evidence_id;
            }
        }
        $facts = [];
        foreach ($rows as $row) {
            $evidenceIds = $evidenceByFact[(int) $row->id] ?? [];
            if ((string) $row->fact_status === 'confirmed'
                && (string) $row->fact_origin !== 'user_assumption'
                && $evidenceIds === []) {
                continue;
            }
            $decoded = $this->decode($row->fact_value);
            $facts[] = new Fact(
                (string) $row->stable_key,
                (int) $row->organization_id,
                (int) $row->project_id,
                (int) $row->session_id,
                (string) $row->source_version,
                (string) $row->entity_stable_key,
                (string) $row->assertion_type,
                $decoded['value'] ?? $decoded,
                is_string($row->fact_unit) ? $row->fact_unit : null,
                (float) $row->confidence,
                (string) $row->fact_origin,
                (string) $row->fact_status,
                $evidenceIds,
                (int) $row->fact_version,
                $row->supersedes_assertion_id === null
                    ? null
                    : ($supersededKeys[(int) $row->supersedes_assertion_id] ?? null),
            );
        }

        return $facts;
    }

    public function currentConflicts(int $organizationId, int $projectId, int $sessionId): array
    {
        if ($organizationId <= 0 || $projectId <= 0 || $sessionId <= 0) {
            throw new InvalidArgumentException('Project model query scope is invalid.');
        }
        $rows = $this->database->table('estimate_generation_project_model_conflicts')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('status', 'unresolved')
            ->whereExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('estimate_generation_project_model_conflict_facts as conflict_fact')
                ->join('estimate_generation_project_model_fact_projections as projection', 'projection.fact_id', '=', 'conflict_fact.fact_id')
                ->whereColumn('conflict_fact.conflict_id', 'estimate_generation_project_model_conflicts.id')
                ->where('projection.is_current', true))
            ->whereNotExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('estimate_generation_project_model_corrections as decision')
                ->whereColumn('decision.target_conflict_key', 'estimate_generation_project_model_conflicts.stable_key')
                ->whereColumn('decision.organization_id', 'estimate_generation_project_model_conflicts.organization_id')
                ->whereColumn('decision.project_id', 'estimate_generation_project_model_conflicts.project_id')
                ->whereColumn('decision.session_id', 'estimate_generation_project_model_conflicts.session_id'))
            ->orderBy('id')->get();
        $result = [];
        foreach ($rows as $row) {
            $factRows = $this->database->table('estimate_generation_project_model_conflict_facts as binding')
                ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'binding.fact_id')
                ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'fact.entity_id')
                ->join('estimate_generation_project_model_fact_projections as projection', function ($join): void {
                    $join->on('projection.fact_id', '=', 'fact.id')
                        ->on('projection.organization_id', '=', 'binding.organization_id')
                        ->on('projection.project_id', '=', 'binding.project_id')
                        ->on('projection.session_id', '=', 'binding.session_id');
                })
                ->where('binding.conflict_id', $row->id)
                ->where('binding.organization_id', $organizationId)
                ->where('binding.project_id', $projectId)
                ->where('binding.session_id', $sessionId)
                ->where('projection.is_current', true)
                ->orderBy('fact.stable_key')
                ->get(['fact.*', 'entity.stable_key as entity_stable_key']);
            $factIds = $factRows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $evidenceByFact = [];
            if ($factIds !== []) {
                foreach ($this->database->table('estimate_generation_project_model_fact_evidence as binding')
                    ->join('estimate_generation_evidence as evidence', function ($join): void {
                        $join->on('evidence.id', '=', 'binding.evidence_id')
                            ->on('evidence.organization_id', '=', 'binding.organization_id')
                            ->on('evidence.project_id', '=', 'binding.project_id')
                            ->on('evidence.session_id', '=', 'binding.session_id');
                    })
                    ->whereIn('binding.fact_id', $factIds)
                    ->where('binding.organization_id', $organizationId)
                    ->where('binding.project_id', $projectId)
                    ->where('binding.session_id', $sessionId)
                    ->whereNull('evidence.invalidated_at')
                    ->whereColumn('evidence.source_version', 'binding.evidence_source_version')
                    ->whereColumn('evidence.invalidation_version', 'binding.evidence_invalidation_version')
                    ->orderBy('binding.fact_id')
                    ->orderBy('binding.evidence_id')
                    ->get(['binding.fact_id', 'binding.evidence_id']) as $binding) {
                    $evidenceByFact[(int) $binding->fact_id][] = 'evidence:'.$binding->evidence_id;
                }
            }
            $facts = [];
            foreach ($factRows as $factRow) {
                $value = $this->decode($factRow->fact_value);
                $facts[] = new Fact(
                    (string) $factRow->stable_key,
                    (int) $factRow->organization_id,
                    (int) $factRow->project_id,
                    (int) $factRow->session_id,
                    (string) $factRow->source_version,
                    (string) $factRow->entity_stable_key,
                    (string) $factRow->assertion_type,
                    $value['value'] ?? $value,
                    is_string($factRow->fact_unit) ? $factRow->fact_unit : null,
                    (float) $factRow->confidence,
                    (string) $factRow->fact_origin,
                    (string) $factRow->fact_status,
                    $evidenceByFact[(int) $factRow->id] ?? [],
                    (int) $factRow->fact_version,
                );
            }
            if (count($facts) >= 2) {
                $result[] = Conflict::between((string) $row->stable_key, $facts, (string) $row->reason);
            }
        }

        return $result;
    }

    public function fact(int $organizationId, int $projectId, int $sessionId, string $factId): ?Fact
    {
        ProjectModelInvariant::id($factId, 'Fact');
        $row = $this->database->table('estimate_generation_project_model_assertions as fact')
            ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'fact.entity_id')
            ->where('fact.organization_id', $organizationId)
            ->where('fact.project_id', $projectId)
            ->where('fact.session_id', $sessionId)
            ->where('fact.stable_key', $factId)
            ->first(['fact.*', 'entity.stable_key as entity_stable_key']);
        if ($row === null) {
            return null;
        }
        $evidenceIds = $this->database->table('estimate_generation_project_model_fact_evidence as binding')
            ->join('estimate_generation_evidence as evidence', 'evidence.id', '=', 'binding.evidence_id')
            ->where('binding.fact_id', $row->id)
            ->where('binding.organization_id', $organizationId)
            ->where('binding.project_id', $projectId)
            ->where('binding.session_id', $sessionId)
            ->whereNull('evidence.invalidated_at')
            ->whereColumn('evidence.source_version', 'binding.evidence_source_version')
            ->whereColumn('evidence.invalidation_version', 'binding.evidence_invalidation_version')
            ->orderBy('binding.evidence_id')->pluck('binding.evidence_id')
            ->map(static fn ($id): string => 'evidence:'.$id)->all();
        $value = $this->decode($row->fact_value);

        return new Fact(
            (string) $row->stable_key,
            (int) $row->organization_id,
            (int) $row->project_id,
            (int) $row->session_id,
            (string) $row->source_version,
            (string) $row->entity_stable_key,
            (string) $row->assertion_type,
            $value['value'] ?? $value,
            is_string($row->fact_unit) ? $row->fact_unit : null,
            (float) $row->confidence,
            (string) $row->fact_origin,
            (string) $row->fact_status,
            $evidenceIds,
            (int) $row->fact_version,
            $row->supersedes_assertion_id === null ? null : $this->factStableKey((int) $row->supersedes_assertion_id),
        );
    }

    public function decisions(int $organizationId, int $projectId, int $sessionId, array $decisionIds): array
    {
        $decisionIds = array_values(array_unique($decisionIds));
        if (count($decisionIds) > 100) {
            throw new InvalidArgumentException('Decision read batch exceeds its limit.');
        }
        foreach ($decisionIds as $decisionId) {
            ProjectModelInvariant::id($decisionId, 'Decision');
        }
        if ($decisionIds === []) {
            return [];
        }
        $rows = $this->database->table('estimate_generation_project_model_corrections')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->whereIn('stable_key', $decisionIds)
            ->orderBy('id')->get();

        return $this->mapDecisionRows($rows);
    }

    public function decisionsForSelectedFacts(int $organizationId, int $projectId, int $sessionId, array $factIds): array
    {
        $factIds = array_values(array_unique($factIds));
        if (count($factIds) > 100) {
            throw new InvalidArgumentException('Decision fact read batch exceeds its limit.');
        }
        foreach ($factIds as $factId) {
            ProjectModelInvariant::id($factId, 'Decision fact');
        }
        if ($factIds === []) {
            return [];
        }
        $rows = $this->database->table('estimate_generation_project_model_corrections')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->whereIn('selected_fact_stable_key', $factIds)
            ->orderBy('id')->get();

        return $this->mapDecisionRows($rows);
    }

    public function completedSynthesisRoleFingerprints(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $sourceVersions,
    ): array {
        $roles = ['arbiter', 'geometry_expert'];
        $rows = $sourceVersions === [] ? collect() : $this->database
            ->table('estimate_generation_ai_role_runs')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('status', 'completed')
            ->whereIn('role', $roles)
            ->whereIn('subject_version', $sourceVersions)
            ->orderBy('role')
            ->orderBy('input_fingerprint')
            ->get(['role', 'input_fingerprint']);
        $result = ['arbiter' => [], 'geometry_expert' => []];
        foreach ($rows as $row) {
            $role = (string) $row->role;
            if (isset($result[$role])) {
                $result[$role][] = (string) $row->input_fingerprint;
            }
        }
        foreach ($result as &$fingerprints) {
            $fingerprints = array_values(array_unique($fingerprints));
        }
        unset($fingerprints);

        return $result;
    }

    private function mapDecisionRows(iterable $rows): array
    {
        return collect($rows)->map(function ($row): Decision {
            $actorType = (string) $row->decision_actor_type;
            $actorId = $actorType === 'user' ? (string) $row->actor_id : (string) $row->system_actor_key;
            $targetId = $row->target_conflict_key === null
                ? (string) $row->selected_fact_stable_key
                : (string) $row->target_conflict_key;

            return new Decision(
                (string) $row->stable_key,
                (int) $row->organization_id,
                (int) $row->project_id,
                (int) $row->session_id,
                (string) $row->source_version,
                $row->target_conflict_key === null ? 'fact' : 'conflict',
                $targetId,
                (string) $row->selected_fact_stable_key,
                $actorType,
                $actorId,
                (string) $row->reason,
                (int) $row->decision_version,
                array_values($this->decode($row->evidence_lineage)),
            );
        })->all();
    }

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
    ): bool {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        if ($providerCalls < 0 || preg_match('/^[a-f0-9]{64}$/D', $inputFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1
            || ! array_is_list($questions) || ! array_is_list($limitations)) {
            throw new InvalidArgumentException('Project understanding result is invalid.');
        }
        $payload = [
            'links' => $links,
            'conflicts' => $conflicts,
            'questions' => $questions,
            'limitations' => $limitations,
            'provider_calls' => $providerCalls,
        ];
        $encodedPayload = $this->json($payload);
        $fingerprint = hash('sha256', $inputFingerprint."\0".$encodedPayload);

        return $this->database->connection()->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $snapshotToken,
            $links,
            $conflicts,
            $questions,
            $limitations,
            $providerCalls,
            $fingerprint,
            $encodedPayload,
        ): bool {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            if (! hash_equals($snapshotToken, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
                return false;
            }
            $existing = $this->database->table('estimate_generation_project_understanding_runs')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('source_version', $sourceVersion)
                ->where('input_fingerprint', $inputFingerprint)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $storedPayload = $this->json($this->decode($existing->result_payload));
                if (! hash_equals((string) $existing->input_fingerprint, $inputFingerprint)
                    || ! hash_equals(
                        (string) $existing->result_fingerprint,
                        hash('sha256', (string) $existing->input_fingerprint."\0".$storedPayload),
                    )
                    || ! hash_equals($storedPayload, $encodedPayload)) {
                    throw new InvalidArgumentException('Project understanding fingerprint collision.');
                }
                $this->activateUnderstandingRun($existing, $links);

                return true;
            }
            $this->deactivateUnderstanding($organizationId, $projectId, $sessionId);
            $this->appendCrossDocumentLinks($links);
            if ($links !== []) {
                $this->database->table('estimate_generation_project_model_cross_document_links')
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->where('session_id', $sessionId)
                    ->where('source_version', $sourceVersion)
                    ->whereIn('operation_identity', array_column($links, 'operation_identity'))
                    ->update(['is_current' => true, 'invalidated_at' => null]);
            }
            $this->appendConflicts($conflicts);
            $this->database->table('estimate_generation_project_understanding_runs')->insert([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'session_id' => $sessionId,
                'source_version' => $sourceVersion,
                'input_fingerprint' => $inputFingerprint,
                'result_fingerprint' => $fingerprint,
                'result_payload' => $encodedPayload,
                'questions' => $this->json($questions),
                'limitations' => $this->json($limitations),
                'provider_calls' => $providerCalls,
                'is_current' => true,
                'created_at' => now(),
            ]);

            return true;
        }, 3);
    }

    public function replayUnderstanding(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
        string $snapshotToken,
    ): ?array {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        if (preg_match('/^[a-f0-9]{64}$/D', $inputFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1) {
            throw new InvalidArgumentException('Project understanding input fingerprint is invalid.');
        }

        return $this->database->connection()->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $snapshotToken,
        ): ?array {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            if (! hash_equals($snapshotToken, $this->understandingSnapshotToken($organizationId, $projectId, $sessionId))) {
                return null;
            }
            $row = $this->database->table('estimate_generation_project_understanding_runs')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('source_version', $sourceVersion)
                ->where('input_fingerprint', $inputFingerprint)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return null;
            }
            $payload = $this->decode($row->result_payload);
            if (! hash_equals(
                (string) $row->result_fingerprint,
                hash('sha256', (string) $row->input_fingerprint."\0".$this->json($payload)),
            )) {
                throw new InvalidArgumentException('Project understanding fingerprint collision.');
            }
            $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
            $this->activateUnderstandingRun($row, $links);

            return $this->currentUnderstanding($organizationId, $projectId, $sessionId);
        }, 3);
    }

    private function understandingSnapshotToken(int $organizationId, int $projectId, int $sessionId): string
    {
        $facts = $this->database->table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->where('projection.organization_id', $organizationId)
            ->where('projection.project_id', $projectId)
            ->where('projection.session_id', $sessionId)
            ->where('projection.is_current', true)
            ->orderBy('fact.id')
            ->get([
                'projection.id as projection_id', 'projection.projection_version',
                'projection.entity_stable_key', 'fact.id', 'fact.stable_key', 'fact.source_version',
                'fact.fact_version', 'fact.fact_status', 'fact.fact_value', 'fact.assertion_type',
                'fact.fact_unit', 'fact.confidence', 'fact.fact_origin',
            ]);
        $factIds = $facts->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $bindings = $factIds === [] ? collect() : $this->database
            ->table('estimate_generation_project_model_fact_evidence as binding')
            ->join('estimate_generation_evidence as evidence', function ($join): void {
                $join->on('evidence.id', '=', 'binding.evidence_id')
                    ->on('evidence.organization_id', '=', 'binding.organization_id')
                    ->on('evidence.project_id', '=', 'binding.project_id')
                    ->on('evidence.session_id', '=', 'binding.session_id')
                    ->on('evidence.source_version', '=', 'binding.evidence_source_version')
                    ->on('evidence.invalidation_version', '=', 'binding.evidence_invalidation_version');
            })
            ->whereIn('binding.fact_id', $factIds)
            ->where('binding.organization_id', $organizationId)
            ->where('binding.project_id', $projectId)
            ->where('binding.session_id', $sessionId)
            ->whereNull('evidence.invalidated_at')
            ->orderBy('binding.fact_id')->orderBy('binding.evidence_id')
            ->get([
                'binding.fact_id', 'binding.evidence_id', 'binding.source_version',
                'binding.evidence_source_version', 'binding.evidence_invalidation_version',
                'evidence.source_ref', 'evidence.source_type', 'evidence.locator',
            ]);
        $stableKeys = $facts->pluck('stable_key')->map(static fn ($key): string => (string) $key)->all();
        $entityKeys = $facts->pluck('entity_stable_key')->unique()->values()->all();
        $entityRows = $entityKeys === [] ? collect() : $this->database
            ->table('estimate_generation_project_model_entities')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->whereIn('stable_key', $entityKeys)
            ->orderBy('stable_key')->orderByDesc('id')->get();
        $decisions = $stableKeys === [] ? collect() : $this->database
            ->table('estimate_generation_project_model_corrections')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->whereIn('selected_fact_stable_key', $stableKeys)
            ->groupBy('selected_fact_stable_key')
            ->orderBy('selected_fact_stable_key')
            ->selectRaw('selected_fact_stable_key, max(id) as id, max(decision_version) as version')
            ->get();
        $evidence = [];
        $bindingRecords = [];
        $sourceVersions = [];
        foreach ($bindings as $binding) {
            $bindingRecords[] = [
                'fact_id' => (int) $binding->fact_id,
                'evidence_id' => (int) $binding->evidence_id,
                'source_version' => (string) $binding->source_version,
                'evidence_source_version' => (string) $binding->evidence_source_version,
                'evidence_invalidation_version' => (int) $binding->evidence_invalidation_version,
            ];
            $evidence[(int) $binding->evidence_id] = [
                'id' => (int) $binding->evidence_id,
                'source_version' => (string) $binding->evidence_source_version,
                'invalidation_version' => (int) $binding->evidence_invalidation_version,
                'locator_hash' => hash('sha256', $this->json([
                    'source_ref' => $binding->source_ref,
                    'source_type' => $binding->source_type,
                    'locator' => $this->decode($binding->locator),
                ])),
            ];
            $sourceVersions[] = (string) $binding->evidence_source_version;
        }
        $factRecords = [];
        foreach ($facts as $fact) {
            $factRecords[] = [
                'id' => (int) $fact->id,
                'stable_key' => (string) $fact->stable_key,
                'version' => (int) $fact->fact_version,
                'projection_id' => (int) $fact->projection_id,
                'projection_version' => (int) $fact->projection_version,
                'status' => (string) $fact->fact_status,
                'value_hash' => hash('sha256', $this->json($this->decode($fact->fact_value))),
                'semantic_hash' => hash('sha256', $this->json([
                    'entity_stable_key' => $fact->entity_stable_key,
                    'type' => $fact->assertion_type,
                    'value' => $this->decode($fact->fact_value),
                    'unit' => $fact->fact_unit,
                    'confidence' => $fact->confidence,
                    'origin' => $fact->fact_origin,
                ])),
            ];
            $sourceVersions[] = (string) $fact->source_version;
        }
        $entities = [];
        foreach ($entityRows as $entity) {
            $stableKey = (string) $entity->stable_key;
            if (isset($entities[$stableKey])) {
                continue;
            }
            $entities[$stableKey] = [
                'id' => (int) $entity->id,
                'stable_key' => $stableKey,
                'source_version' => (string) $entity->source_version,
                'content_hash' => hash('sha256', $this->json([
                    'kind' => $entity->entity_kind,
                    'payload' => $this->decode($entity->payload),
                ])),
            ];
            $sourceVersions[] = (string) $entity->source_version;
        }
        $sourceVersions = array_values(array_unique($sourceVersions));
        sort($sourceVersions, SORT_STRING);
        $roleRuns = $sourceVersions === [] ? collect() : $this->database
            ->table('estimate_generation_ai_role_runs')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('status', 'completed')
            ->whereIn('role', ['arbiter', 'geometry_expert'])
            ->whereIn('subject_version', $sourceVersions)
            ->orderBy('role')
            ->orderBy('subject_version')
            ->orderBy('input_fingerprint')
            ->get(['role', 'subject_version', 'input_fingerprint']);

        return ProjectUnderstandingInputFingerprint::fromExactState([
            'scope' => ['organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId],
            'source_versions' => array_values(array_unique($sourceVersions)),
            'entities' => array_values($entities),
            'facts' => $factRecords,
            'bindings' => $bindingRecords,
            'evidence' => array_values($evidence),
            'decisions' => $decisions->map(static fn ($decision): array => [
                'id' => (int) $decision->id,
                'selected_fact_id' => (string) $decision->selected_fact_stable_key,
                'version' => (int) $decision->version,
            ])->all(),
            'role_runs' => $roleRuns->map(static fn ($run): array => [
                'role' => (string) $run->role,
                'subject_version' => (string) $run->subject_version,
                'input_fingerprint' => (string) $run->input_fingerprint,
            ])->all(),
        ]);
    }

    private function lockUnderstandingScope(int $organizationId, int $projectId, int $sessionId): void
    {
        $connection = $this->database->connection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                implode(':', ['project-understanding', $organizationId, $projectId, $sessionId]),
            ]);

            return;
        }
        $this->database->table('estimate_generation_sessions')->where('id', $sessionId)->lockForUpdate()->first();
    }

    private function deactivateUnderstanding(int $organizationId, int $projectId, int $sessionId): void
    {
        foreach (['estimate_generation_project_understanding_runs', 'estimate_generation_project_model_cross_document_links'] as $table) {
            $this->database->table($table)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'invalidated_at' => now()]);
        }
    }

    private function activateUnderstandingRun(object $run, array $links): void
    {
        $this->deactivateUnderstanding((int) $run->organization_id, (int) $run->project_id, (int) $run->session_id);
        if ($links !== []) {
            $operationIdentities = array_values(array_unique(array_column($links, 'operation_identity')));
            $updated = $this->database->table('estimate_generation_project_model_cross_document_links')
                ->where('organization_id', $run->organization_id)
                ->where('project_id', $run->project_id)
                ->where('session_id', $run->session_id)
                ->where('source_version', $run->source_version)
                ->whereIn('operation_identity', $operationIdentities)
                ->update(['is_current' => true, 'invalidated_at' => null]);
            if ($updated !== count($operationIdentities)) {
                throw new InvalidArgumentException('Project understanding links cannot be restored.');
            }
        }
        if ($this->database->table('estimate_generation_project_understanding_runs')->where('id', $run->id)->update([
            'is_current' => true,
            'invalidated_at' => null,
        ]) !== 1) {
            throw new InvalidArgumentException('Project understanding run cannot be restored.');
        }
    }

    public function currentUnderstanding(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $row = $this->database->table('estimate_generation_project_understanding_runs')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('is_current', true)
            ->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }

        $linkRows = $this->database->table('estimate_generation_project_model_cross_document_links as link')
            ->join('estimate_generation_project_model_assertions as left_fact', 'left_fact.id', '=', 'link.left_fact_id')
            ->join('estimate_generation_project_model_assertions as right_fact', 'right_fact.id', '=', 'link.right_fact_id')
            ->where('link.organization_id', $organizationId)
            ->where('link.project_id', $projectId)
            ->where('link.session_id', $sessionId)
            ->where('link.source_version', (string) $row->source_version)
            ->where('link.is_current', true)
            ->orderBy('link.stable_key')
            ->get(['link.*', 'left_fact.stable_key as left_stable_key', 'right_fact.stable_key as right_stable_key']);
        $evidenceByLink = [];
        $linkIds = $linkRows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($linkIds !== []) {
            foreach ($this->database->table('estimate_generation_project_model_cross_link_evidence as binding')
                ->join('estimate_generation_evidence as evidence', function ($join): void {
                    $join->on('evidence.id', '=', 'binding.evidence_id')
                        ->on('evidence.organization_id', '=', 'binding.organization_id')
                        ->on('evidence.project_id', '=', 'binding.project_id')
                        ->on('evidence.session_id', '=', 'binding.session_id');
                })
                ->whereIn('binding.link_id', $linkIds)
                ->where('binding.organization_id', $organizationId)
                ->where('binding.project_id', $projectId)
                ->where('binding.session_id', $sessionId)
                ->where('binding.source_version', (string) $row->source_version)
                ->whereNull('evidence.invalidated_at')
                ->orderBy('binding.link_id')
                ->orderBy('binding.side')
                ->orderBy('binding.evidence_id')
                ->get(['binding.link_id', 'binding.side', 'evidence.id as evidence_id']) as $binding) {
                $evidenceByLink[(int) $binding->link_id][(string) $binding->side][] = 'evidence:'.$binding->evidence_id;
            }
        }
        $links = $linkRows->map(fn ($link): array => [
            'id' => (string) $link->stable_key,
            'organization_id' => (int) $link->organization_id,
            'project_id' => (int) $link->project_id,
            'session_id' => (int) $link->session_id,
            'source_version' => (string) $link->source_version,
            'left_fact_id' => (string) $link->left_stable_key,
            'right_fact_id' => (string) $link->right_stable_key,
            'strategy' => (string) $link->strategy,
            'match_key' => (string) $link->match_key,
            'reason' => (string) $link->reason,
            'strategy_version' => (int) $link->strategy_version,
            'operation_identity' => (string) $link->operation_identity,
            'status' => (string) $link->status,
            'candidate_fact_ids' => array_values($this->decode($link->candidate_fact_ids)),
            'candidate_evidence_ids' => array_values($this->decode($link->candidate_evidence_ids)),
            'evidence' => [
                'left' => $evidenceByLink[(int) $link->id]['left'] ?? [],
                'right' => $evidenceByLink[(int) $link->id]['right'] ?? [],
            ],
        ])->all();
        $resultPayload = $this->decode($row->result_payload);
        $persistedLinks = is_array($resultPayload['links'] ?? null) ? $resultPayload['links'] : [];
        $linksByOperation = [];
        foreach ($links as $link) {
            $linksByOperation[$link['operation_identity']] = $link;
        }
        foreach ($persistedLinks as $persisted) {
            $operationIdentity = is_array($persisted) ? ($persisted['operation_identity'] ?? null) : null;
            if (! is_string($operationIdentity) || ! isset($linksByOperation[$operationIdentity])) {
                throw new InvalidArgumentException('Project understanding link history is incomplete.');
            }
        }
        if (count($persistedLinks) !== count($linksByOperation)) {
            throw new InvalidArgumentException('Project understanding link history is inconsistent.');
        }
        $links = array_values($persistedLinks);

        return [
            'source_version' => (string) $row->source_version,
            'input_fingerprint' => (string) $row->input_fingerprint,
            'links' => $links,
            'conflicts' => is_array($resultPayload['conflicts'] ?? null)
                ? array_values($resultPayload['conflicts'])
                : [],
            'questions' => array_values($this->decode($row->questions)),
            'limitations' => array_values($this->decode($row->limitations)),
            'provider_calls' => (int) $row->provider_calls,
        ];
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
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        $payload = [];
        foreach ($recommendations as $recommendation) {
            if (! $recommendation instanceof TechnologyRecommendation
                || [$recommendation->organizationId, $recommendation->projectId, $recommendation->sessionId, $recommendation->sourceVersion]
                    !== [$organizationId, $projectId, $sessionId, $sourceVersion]
                || $recommendation->catalogVersion !== $catalogVersion
                || ! hash_equals($recommendation->catalogHash, $catalogHash)) {
                throw new InvalidArgumentException('Technology recommendation batch is outside the requested scope.');
            }
            $payload[] = $recommendation->toArray();
        }
        if (count($payload) > 50 || count($limitations) > 20) {
            throw new InvalidArgumentException('Technology recommendation batch exceeds its limits.');
        }
        $resultFingerprint = hash('sha256', $this->json([$payload, $limitations]));

        return $this->database->connection()->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $catalogVersion,
            $catalogHash,
            $payload,
            $limitations,
            $resultFingerprint,
        ): bool {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);
            if (! hash_equals($inputFingerprint, $capture['token'])) {
                return false;
            }
            $existing = $this->database->table('estimate_generation_technology_planning_runs')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('source_version', $sourceVersion)
                ->where('input_fingerprint', $inputFingerprint)
                ->where('catalog_version', $catalogVersion)
                ->where('catalog_hash', $catalogHash)
                ->first();
            if ($existing !== null) {
                if (! $this->isLatestTechnologyRun($existing, $organizationId, $projectId, $sessionId, $sourceVersion, $inputFingerprint)) {
                    return false;
                }
                if (! hash_equals((string) $existing->result_fingerprint, $resultFingerprint)) {
                    throw new InvalidArgumentException('Technology recommendation replay content differs.');
                }
                $this->activateTechnologyPlanningRun($existing, $organizationId, $projectId, $sessionId);

                return true;
            }
            $this->database->table('estimate_generation_technology_planning_runs')
                ->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('session_id', $sessionId)->where('is_current', true)
                ->update(['is_current' => false, 'invalidated_at' => now()]);
            $runId = (int) $this->database->table('estimate_generation_technology_planning_runs')->insertGetId([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'session_id' => $sessionId,
                'source_version' => $sourceVersion,
                'input_fingerprint' => $inputFingerprint,
                'catalog_version' => $catalogVersion,
                'catalog_hash' => $catalogHash,
                'result_fingerprint' => $resultFingerprint,
                'limitations' => $this->json($limitations),
                'is_current' => true,
                'created_at' => now(),
            ]);
            foreach ($payload as $recommendation) {
                $recommendationId = (int) $this->database->table('estimate_generation_technology_recommendations')->insertGetId([
                    'planning_run_id' => $runId,
                    'decision_key' => $recommendation['decision_key'],
                    'target_fact_stable_key' => $recommendation['target_fact_id'],
                    'question' => $recommendation['question'],
                    'conditional' => $recommendation['conditional'],
                    'missing_facts' => $this->json($recommendation['missing_facts']),
                    'response_options' => $this->json($recommendation['response_options']),
                    'created_at' => now(),
                ]);
                $rows = [];
                foreach ($recommendation['options'] as $rank => $option) {
                    $rows[] = [
                        'recommendation_id' => $recommendationId,
                        'system_id' => $option['system']['id'],
                        'rank' => $rank + 1,
                        'recommended' => $option['recommended'],
                        'score' => $option['score'],
                        'label' => $option['label'],
                        'explanation' => $option['explanation'],
                        'applicability_status' => $option['applicability_status'],
                        'applicability_reasons' => $this->json($option['applicability_reasons']),
                        'applicability_evidence' => $this->json($option['applicability_evidence']),
                        'score_contributions' => $this->json($option['score_contributions']),
                        'system_payload' => $this->json($option['system']),
                        'created_at' => now(),
                    ];
                }
                foreach (array_chunk($rows, 50) as $chunk) {
                    $this->database->table('estimate_generation_technology_recommendation_options')->insert($chunk);
                }
            }

            return true;
        }, 3);
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
        $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);
        if (! hash_equals($inputFingerprint, $capture['token'])) {
            return null;
        }
        $row = $this->database->table('estimate_generation_technology_planning_runs')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->where('source_version', $sourceVersion)
            ->where('input_fingerprint', $inputFingerprint)->where('catalog_version', $catalogVersion)
            ->where('catalog_hash', $catalogHash)->first();
        if ($row === null) {
            return null;
        }

        return $this->database->connection()->transaction(function () use (
            $row,
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
        ): ?array {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $locked = $this->database->table('estimate_generation_technology_planning_runs')->where('id', $row->id)->first();
            $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);
            if ($locked === null || ! hash_equals($inputFingerprint, $capture['token'])
                || ! $this->isLatestTechnologyRun($locked, $organizationId, $projectId, $sessionId, $sourceVersion, $inputFingerprint)) {
                return null;
            }
            $this->activateTechnologyPlanningRun($locked, $organizationId, $projectId, $sessionId);

            return $this->technologyPlanningRun($locked);
        }, 3);
    }

    public function currentTechnologyRecommendations(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $row = $this->database->table('estimate_generation_technology_planning_runs')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->where('is_current', true)->orderByDesc('id')->first();

        if ($row === null) {
            return null;
        }
        $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);

        return hash_equals((string) $row->input_fingerprint, $capture['token'])
            ? $this->technologyPlanningRun($row)
            : null;
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
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        $payload = [];
        foreach ($findings as $finding) {
            if (! $finding instanceof CompletenessFinding) {
                throw new InvalidArgumentException('Completeness finding batch is invalid.');
            }
            $payload[] = $finding->toArray();
        }
        if (count($payload) > 100 || count($limitations) > 20) {
            throw new InvalidArgumentException('Completeness batch exceeds its limits.');
        }
        $resultFingerprint = hash('sha256', $this->json([$payload, $limitations]));

        return $this->database->connection()->transaction(function () use (
            $organizationId, $projectId, $sessionId, $sourceVersion, $inputFingerprint,
            $catalogVersion, $catalogHash, $ruleCatalogVersion, $ruleCatalogHash,
            $payload, $limitations, $resultFingerprint,
        ): bool {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);
            if (! hash_equals($inputFingerprint, $capture['token'])) {
                return false;
            }
            $query = $this->database->table('estimate_generation_completeness_runs')
                ->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('session_id', $sessionId)->where('source_version', $sourceVersion)
                ->where('input_fingerprint', $inputFingerprint)->where('catalog_version', $catalogVersion)
                ->where('catalog_hash', $catalogHash)->where('rule_catalog_version', $ruleCatalogVersion)
                ->where('rule_catalog_hash', $ruleCatalogHash);
            $existing = $query->first();
            if ($existing !== null) {
                if (! $this->isLatestCompletenessRun($existing, $organizationId, $projectId, $sessionId, $sourceVersion, $inputFingerprint)) {
                    return false;
                }
                if (! hash_equals((string) $existing->result_fingerprint, $resultFingerprint)) {
                    throw new InvalidArgumentException('Completeness replay content differs.');
                }
                $this->activateCompletenessRun($existing, $organizationId, $projectId, $sessionId);

                return true;
            }
            $this->database->table('estimate_generation_completeness_runs')
                ->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('session_id', $sessionId)->where('is_current', true)
                ->update(['is_current' => false, 'invalidated_at' => now()]);
            $runId = (int) $this->database->table('estimate_generation_completeness_runs')->insertGetId([
                'organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId,
                'source_version' => $sourceVersion, 'input_fingerprint' => $inputFingerprint,
                'catalog_version' => $catalogVersion, 'catalog_hash' => $catalogHash,
                'rule_catalog_version' => $ruleCatalogVersion, 'rule_catalog_hash' => $ruleCatalogHash,
                'result_fingerprint' => $resultFingerprint, 'limitations' => $this->json($limitations),
                'is_current' => true, 'created_at' => now(),
            ]);
            foreach ($payload as $finding) {
                $findingId = (int) $this->database->table('estimate_generation_completeness_findings')->insertGetId([
                    'completeness_run_id' => $runId, 'rule_id' => $finding['ruleId'],
                    'rule_version' => $finding['ruleVersion'], 'rule_hash' => $finding['ruleHash'],
                    'finding_stable_key' => $finding['stableKey'], 'finding_version' => $finding['version'],
                    'classification' => $finding['classification'], 'status' => $finding['status'],
                    'severity' => $finding['severity'], 'impact' => $finding['impact'],
                    'confidence' => (string) $finding['confidence'],
                    'evidence_fact_ids' => $this->json($finding['evidenceFactIds']),
                    'related_entity_ids' => $this->json($finding['relatedEntityIds']),
                    'related_fact_types' => $this->json($finding['relatedFactTypes']),
                    'applicability' => $this->json($finding['applicability']),
                    'exclusion_policy' => $this->json($finding['exclusionPolicy']),
                    'exclusion_decision' => $finding['exclusionDecision'] === null ? null : $this->json($finding['exclusionDecision']),
                    'created_at' => now(),
                ]);
                if ($finding['workPackage'] !== null) {
                    $package = $finding['workPackage'];
                    $this->database->table('estimate_generation_technology_work_packages')->insert([
                        'completeness_finding_id' => $findingId, 'stable_key' => $package['id'],
                        'works' => $this->json($package['works']), 'materials' => $this->json($package['materials']),
                        'machinery' => $this->json($package['machinery']), 'norm_intents' => $this->json($package['normIntents']),
                        'quantity_formulas' => $this->json($package['quantityFormulas']),
                        'dependencies' => $this->json($package['dependencies']),
                        'regional_price_availability' => $this->json($package['regionalPriceAvailability']),
                        'assumptions' => $this->json($package['assumptions']), 'risks' => $this->json($package['risks']),
                        'provenance' => $this->json($package['provenance']), 'created_at' => now(),
                    ]);
                }
            }

            return true;
        }, 3);
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
        $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);
        if (! hash_equals($inputFingerprint, $capture['token'])) {
            return null;
        }
        $row = $this->database->table('estimate_generation_completeness_runs')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->where('source_version', $sourceVersion)
            ->where('input_fingerprint', $inputFingerprint)->where('catalog_version', $catalogVersion)
            ->where('catalog_hash', $catalogHash)->where('rule_catalog_version', $ruleCatalogVersion)
            ->where('rule_catalog_hash', $ruleCatalogHash)->first();
        if ($row === null) {
            return null;
        }

        return $this->database->connection()->transaction(function () use (
            $row,
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
        ): ?array {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $locked = $this->database->table('estimate_generation_completeness_runs')->where('id', $row->id)->first();
            $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);
            if ($locked === null || ! hash_equals($inputFingerprint, $capture['token'])
                || ! $this->isLatestCompletenessRun($locked, $organizationId, $projectId, $sessionId, $sourceVersion, $inputFingerprint)) {
                return null;
            }
            $this->activateCompletenessRun($locked, $organizationId, $projectId, $sessionId);

            return $this->completenessRun($locked);
        }, 3);
    }

    public function currentCompleteness(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $row = $this->database->table('estimate_generation_completeness_runs')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->where('is_current', true)->orderByDesc('id')->first();

        if ($row === null) {
            return null;
        }
        $capture = $this->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);

        return hash_equals((string) $row->input_fingerprint, $capture['token'])
            ? $this->completenessRun($row)
            : null;
    }

    public function invalidateSourceVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $replacementSourceVersion,
    ): void {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $replacementSourceVersion);
        if (hash_equals($sourceVersion, $replacementSourceVersion)) {
            throw new InvalidArgumentException('Replacement source version must differ.');
        }
        $this->database->connection()->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $replacementSourceVersion,
        ): void {
            $this->lockUnderstandingScope($organizationId, $projectId, $sessionId);
            $scope = static fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('is_current', true);
            $scope($this->database->table('estimate_generation_project_model_fact_projections'))
                ->whereExists(static fn ($query) => $query
                    ->selectRaw('1')
                    ->from('estimate_generation_project_model_fact_evidence as binding')
                    ->whereColumn('binding.fact_id', 'estimate_generation_project_model_fact_projections.fact_id')
                    ->where('binding.evidence_source_version', $sourceVersion))
                ->update([
                    'is_current' => false,
                    'replacement_source_version' => $replacementSourceVersion,
                    'invalidated_at' => now(),
                ]);
            $scope($this->database->table('estimate_generation_project_model_cross_document_links'))
                ->where(static fn ($query) => $query
                    ->whereExists(static fn ($binding) => $binding
                        ->selectRaw('1')->from('estimate_generation_project_model_fact_evidence as left_binding')
                        ->whereColumn('left_binding.fact_id', 'estimate_generation_project_model_cross_document_links.left_fact_id')
                        ->where('left_binding.evidence_source_version', $sourceVersion))
                    ->orWhereExists(static fn ($binding) => $binding
                        ->selectRaw('1')->from('estimate_generation_project_model_fact_evidence as right_binding')
                        ->whereColumn('right_binding.fact_id', 'estimate_generation_project_model_cross_document_links.right_fact_id')
                        ->where('right_binding.evidence_source_version', $sourceVersion)))
                ->update([
                    'is_current' => false,
                    'invalidated_at' => now(),
                ]);
            $scope($this->database->table('estimate_generation_project_understanding_runs'))->update([
                'is_current' => false,
                'invalidated_at' => now(),
            ]);
            $scope($this->database->table('estimate_generation_technology_planning_runs'))->update([
                'is_current' => false,
                'invalidated_at' => now(),
            ]);
            $scope($this->database->table('estimate_generation_completeness_runs'))->update([
                'is_current' => false,
                'invalidated_at' => now(),
            ]);
            $this->database->table('estimate_generation_project_model_derived_quantity_projections')
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('source_version', $sourceVersion)
                ->delete();
        }, 3);
    }

    private function activateTechnologyPlanningRun(object $run, int $organizationId, int $projectId, int $sessionId): void
    {
        $this->database->table('estimate_generation_technology_planning_runs')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->where('is_current', true)->where('id', '<>', $run->id)
            ->update(['is_current' => false, 'invalidated_at' => now()]);
        $this->database->table('estimate_generation_technology_planning_runs')->where('id', $run->id)->update([
            'is_current' => true,
            'invalidated_at' => null,
        ]);
    }

    private function isLatestTechnologyRun(
        object $run,
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
    ): bool {
        $latest = $this->database->table('estimate_generation_technology_planning_runs')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', $sourceVersion)
            ->where('input_fingerprint', $inputFingerprint)
            ->orderByDesc('id')
            ->first(['id']);

        return $latest !== null && (int) $latest->id === (int) $run->id;
    }

    private function technologyPlanningRun(object $run): array
    {
        $recommendations = [];
        $rows = $this->database->table('estimate_generation_technology_recommendations')
            ->where('planning_run_id', $run->id)->orderBy('decision_key')->get();
        $optionsByRecommendation = [];
        $recommendationIds = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($recommendationIds !== []) {
            foreach ($this->database->table('estimate_generation_technology_recommendation_options')
                ->whereIn('recommendation_id', $recommendationIds)
                ->orderBy('recommendation_id')->orderBy('rank')->get() as $option) {
                $optionsByRecommendation[(int) $option->recommendation_id][] = [
                    'system' => $this->decode($option->system_payload),
                    'score' => (int) $option->score,
                    'score_contributions' => array_values($this->decode($option->score_contributions)),
                    'recommended' => (bool) $option->recommended,
                    'label' => (string) $option->label,
                    'explanation' => (string) $option->explanation,
                    'applicability_status' => (string) $option->applicability_status,
                    'applicability_reasons' => array_values($this->decode($option->applicability_reasons)),
                    'applicability_evidence' => array_values($this->decode($option->applicability_evidence)),
                ];
            }
        }
        foreach ($rows as $row) {
            $recommendations[] = TechnologyRecommendation::fromArray([
                'decision_key' => (string) $row->decision_key,
                'target_fact_id' => (string) $row->target_fact_stable_key,
                'organization_id' => (int) $run->organization_id,
                'project_id' => (int) $run->project_id,
                'session_id' => (int) $run->session_id,
                'source_version' => (string) $run->source_version,
                'catalog_version' => (string) $run->catalog_version,
                'catalog_hash' => (string) $run->catalog_hash,
                'options' => $optionsByRecommendation[(int) $row->id] ?? [],
                'response_options' => array_values($this->decode($row->response_options)),
                'question' => (string) $row->question,
                'conditional' => (bool) $row->conditional,
                'missing_facts' => array_values($this->decode($row->missing_facts)),
                'auto_apply' => false,
            ]);
        }

        return [
            'run_id' => (int) $run->id,
            'source_version' => (string) $run->source_version,
            'input_fingerprint' => (string) $run->input_fingerprint,
            'catalog_version' => (string) $run->catalog_version,
            'catalog_hash' => (string) $run->catalog_hash,
            'recommendations' => $recommendations,
            'limitations' => array_values($this->decode($run->limitations)),
            'is_current' => (bool) $run->is_current,
        ];
    }

    private function activateCompletenessRun(object $run, int $organizationId, int $projectId, int $sessionId): void
    {
        $this->database->table('estimate_generation_completeness_runs')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->where('is_current', true)->where('id', '<>', $run->id)
            ->update(['is_current' => false, 'invalidated_at' => now()]);
        $this->database->table('estimate_generation_completeness_runs')->where('id', $run->id)->update([
            'is_current' => true,
            'invalidated_at' => null,
        ]);
    }

    private function isLatestCompletenessRun(
        object $run,
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $inputFingerprint,
    ): bool {
        $latest = $this->database->table('estimate_generation_completeness_runs')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', $sourceVersion)
            ->where('input_fingerprint', $inputFingerprint)
            ->orderByDesc('id')
            ->first(['id']);

        return $latest !== null && (int) $latest->id === (int) $run->id;
    }

    private function completenessRun(object $run): array
    {
        $rows = $this->database->table('estimate_generation_completeness_findings')
            ->where('completeness_run_id', $run->id)->orderBy('rule_id')->get();
        $findingIds = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $packages = [];
        if ($findingIds !== []) {
            foreach ($this->database->table('estimate_generation_technology_work_packages')
                ->whereIn('completeness_finding_id', $findingIds)->get() as $package) {
                $packages[(int) $package->completeness_finding_id] = new TechnologyWorkPackage(
                    (string) $package->stable_key,
                    array_values($this->decode($package->works)),
                    array_values($this->decode($package->materials)),
                    array_values($this->decode($package->machinery)),
                    array_values($this->decode($package->norm_intents)),
                    array_values($this->decode($package->quantity_formulas)),
                    array_values($this->decode($package->dependencies)),
                    $this->decode($package->regional_price_availability),
                    array_values($this->decode($package->assumptions)),
                    array_values($this->decode($package->risks)),
                    $this->decode($package->provenance),
                );
            }
        }
        $findings = [];
        foreach ($rows as $row) {
            $findings[] = new CompletenessFinding(
                (string) $row->rule_id,
                (string) $row->rule_version,
                (string) $row->rule_hash,
                (string) $row->finding_stable_key,
                (int) $row->finding_version,
                (string) $row->classification,
                (string) $row->status,
                (string) $row->severity,
                (string) $row->impact,
                (float) $row->confidence,
                array_values($this->decode($row->evidence_fact_ids)),
                array_values($this->decode($row->related_entity_ids)),
                array_values($this->decode($row->related_fact_types)),
                $this->decode($row->applicability),
                $this->decode($row->exclusion_policy),
                $row->exclusion_decision === null ? null : $this->decode($row->exclusion_decision),
                $packages[(int) $row->id] ?? null,
            );
        }

        return [
            'run_id' => (int) $run->id,
            'source_version' => (string) $run->source_version,
            'input_fingerprint' => (string) $run->input_fingerprint,
            'catalog_version' => (string) $run->catalog_version,
            'catalog_hash' => (string) $run->catalog_hash,
            'rule_catalog_version' => (string) $run->rule_catalog_version,
            'rule_catalog_hash' => (string) $run->rule_catalog_hash,
            'findings' => $findings,
            'limitations' => array_values($this->decode($run->limitations)),
            'is_current' => (bool) $run->is_current,
        ];
    }

    private function projectCurrentFact(Fact $fact, int $projectionScopeId, int $entityDatabaseId): void
    {
        $factDatabaseId = $this->database->table('estimate_generation_project_model_assertions')
            ->where('building_model_id', $projectionScopeId)->where('stable_key', $fact->id)->value('id');
        $current = $this->database->table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as current_fact', 'current_fact.id', '=', 'projection.fact_id')
            ->where('projection.organization_id', $fact->organizationId)
            ->where('projection.project_id', $fact->projectId)
            ->where('projection.session_id', $fact->sessionId)
            ->where('projection.entity_stable_key', $fact->entityId)
            ->where('projection.fact_type', $fact->type)
            ->where('projection.is_current', true)->lockForUpdate()
            ->first(['projection.*']);
        if ($current !== null && (int) $current->fact_id === (int) $factDatabaseId) {
            return;
        }
        if ($current !== null
            && (string) $current->source_version === $fact->sourceVersion
            && (int) $current->projection_version >= $fact->version) {
            return;
        }
        if ($current !== null) {
            $this->database->table('estimate_generation_project_model_fact_projections')
                ->where('id', $current->id)->update([
                    'is_current' => false,
                    'replacement_source_version' => $fact->sourceVersion,
                    'invalidated_at' => now(),
                ]);
        }
        $this->database->table('estimate_generation_project_model_fact_projections')->insertOrIgnore([
            'organization_id' => $fact->organizationId,
            'project_id' => $fact->projectId,
            'session_id' => $fact->sessionId,
            'source_version' => $fact->sourceVersion,
            'fact_id' => (int) $factDatabaseId,
            'entity_stable_key' => $fact->entityId,
            'fact_type' => $fact->type,
            'projection_version' => $fact->version,
            'is_current' => true,
            'created_at' => now(),
        ]);
    }

    private function projectionScopeId(object $record): int
    {
        if (! isset($record->sessionId) || ! is_int($record->sessionId) || $record->sessionId < 1) {
            throw new InvalidArgumentException('Project model source version is outside the requested scope.');
        }

        return $record->sessionId;
    }

    private function entityRow(Fact $fact): object
    {
        $row = $this->database->table('estimate_generation_project_model_entities')
            ->where('organization_id', $fact->organizationId)->where('project_id', $fact->projectId)
            ->where('session_id', $fact->sessionId)->where('source_version', $fact->sourceVersion)
            ->where('stable_key', $fact->entityId)->first(['id']);
        if ($row === null) {
            throw new InvalidArgumentException('Project model fact entity is outside the requested scope.');
        }

        return $row;
    }

    private function factDatabaseId(object $scope, string $stableKey): int
    {
        return (int) $this->factRow($scope, $stableKey)->id;
    }

    private function factStableKey(int $id): ?string
    {
        $stableKey = $this->database->table('estimate_generation_project_model_assertions')
            ->where('id', $id)->value('stable_key');

        return is_string($stableKey) ? $stableKey : null;
    }

    private function assertDerivedOperands(array $quantities): void
    {
        $factIds = [];
        $scopes = [];
        foreach ($quantities as $quantity) {
            if (! $quantity instanceof DerivedQuantity) {
                throw new InvalidArgumentException('Derived quantity batch is invalid.');
            }
            foreach ($quantity->operands as $operand) {
                $factIds[] = $operand['fact_id'];
            }
            $scopes[] = [
                $quantity->organizationId,
                $quantity->projectId,
                $quantity->sessionId,
                $quantity->sourceVersion,
            ];
        }
        $factIds = array_values(array_unique($factIds));
        $rows = $this->database->table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->where('projection.is_current', true)
            ->whereIn('fact.stable_key', $factIds)
            ->where(function ($query) use ($scopes): void {
                foreach (array_unique($scopes, SORT_REGULAR) as [$organizationId, $projectId, $sessionId, $sourceVersion]) {
                    $query->orWhere(static fn ($scope) => $scope
                        ->where('projection.organization_id', $organizationId)
                        ->where('projection.project_id', $projectId)
                        ->where('projection.session_id', $sessionId)
                        ->where('projection.source_version', $sourceVersion));
                }
            })
            ->get([
                'fact.id as database_id', 'fact.stable_key', 'fact.organization_id', 'fact.project_id',
                'fact.session_id', 'fact.source_version', 'fact.fact_status', 'fact.fact_version',
                'projection.projection_version',
            ]);
        $databaseIds = $rows->pluck('database_id')->map(static fn ($id): int => (int) $id)->all();
        $evidenceByFact = [];
        if ($databaseIds !== []) {
            foreach ($this->database->table('estimate_generation_project_model_fact_evidence')
                ->whereIn('fact_id', $databaseIds)->orderBy('evidence_id')->get(['fact_id', 'evidence_id']) as $binding) {
                $evidenceByFact[(int) $binding->fact_id][] = 'evidence:'.$binding->evidence_id;
            }
        }
        $evidenceByStableKey = [];
        foreach ($rows as $row) {
            $key = implode(':', [
                $row->organization_id,
                $row->project_id,
                $row->session_id,
                $row->source_version,
                $row->stable_key,
            ]);
            $evidenceByStableKey[$key] = $evidenceByFact[(int) $row->database_id] ?? [];
        }
        $decisionByFact = [];
        foreach ($this->database->table('estimate_generation_project_model_corrections')
            ->whereIn('selected_fact_stable_key', $factIds)
            ->where(function ($query) use ($scopes): void {
                foreach (array_unique($scopes, SORT_REGULAR) as [$organizationId, $projectId, $sessionId, $sourceVersion]) {
                    $query->orWhere(static fn ($scope) => $scope
                        ->where('organization_id', $organizationId)
                        ->where('project_id', $projectId)
                        ->where('session_id', $sessionId)
                        ->where('source_version', $sourceVersion));
                }
            })
            ->get([
                'organization_id', 'project_id', 'session_id', 'source_version',
                'selected_fact_stable_key', 'stable_key', 'evidence_lineage',
            ]) as $decision) {
            $key = implode(':', [
                $decision->organization_id,
                $decision->project_id,
                $decision->session_id,
                $decision->source_version,
                $decision->selected_fact_stable_key,
            ]);
            if (! DecisionEvidenceLineage::isTrusted(
                $this->decode($decision->evidence_lineage),
                $evidenceByStableKey[$key] ?? [],
            )) {
                continue;
            }
            $decisionByFact[$key][] = (string) $decision->stable_key;
        }
        $ready = [];
        foreach ($rows as $row) {
            $key = implode(':', [
                $row->organization_id,
                $row->project_id,
                $row->session_id,
                $row->source_version,
                $row->stable_key,
            ]);
            $ready[$key] = [
                'status' => (string) $row->fact_status,
                'version' => (int) $row->fact_version,
                'projection_version' => (int) $row->projection_version,
                'evidence_ids' => $evidenceByFact[(int) $row->database_id] ?? [],
                'decision_ids' => $decisionByFact[$key] ?? [],
            ];
        }
        foreach ($quantities as $quantity) {
            if ($quantity->status !== 'confirmed') {
                continue;
            }
            foreach ($quantity->operands as $operand) {
                $key = implode(':', [
                    $quantity->organizationId,
                    $quantity->projectId,
                    $quantity->sessionId,
                    $quantity->sourceVersion,
                    $operand['fact_id'],
                ]);
                $fact = $ready[$key] ?? null;
                $hasLineage = $fact !== null && (
                    array_diff($operand['evidence_ids'], $fact['evidence_ids']) === []
                    || (is_string($operand['decision_id']) && in_array($operand['decision_id'], $fact['decision_ids'], true))
                );
                if ($fact === null || $fact['status'] !== 'confirmed'
                    || $fact['version'] !== $operand['projection_version']
                    || $fact['projection_version'] !== $operand['projection_version']
                    || ! $operand['current'] || $operand['status'] !== 'confirmed' || ! $hasLineage) {
                    throw new InvalidArgumentException('Confirmed derived quantity has a non-current or untrusted operand.');
                }
            }
        }
    }

    private function factRow(object $scope, string $stableKey): object
    {
        $row = $this->database->table('estimate_generation_project_model_assertions')
            ->where('organization_id', $scope->organizationId)->where('project_id', $scope->projectId)
            ->where('session_id', $scope->sessionId)->where('source_version', $scope->sourceVersion)
            ->where('stable_key', $stableKey)->first();
        if ($row === null) {
            throw new InvalidArgumentException('Project model fact is outside the requested scope.');
        }

        return $row;
    }

    private function legacySource(string $origin): string
    {
        return match ($origin) {
            'document' => 'reconciled_geometry',
            'ai_inference', 'ai_technology_recommendation', 'unresolved' => 'ai_candidate',
            'user_assumption' => 'reconciled_geometry',
            default => throw new InvalidArgumentException('Project model fact origin is invalid.'),
        };
    }

    private function evidenceRowsForFacts(array $facts): array
    {
        $groups = [];
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Project model fact batch is invalid.');
            }
            foreach ($fact->evidenceIds as $evidenceId) {
                $numericId = $this->evidenceDatabaseId($evidenceId);
                $scope = $fact->organizationId.':'.$fact->projectId.':'.$fact->sessionId;
                $groups[$scope]['record'] = $fact;
                $groups[$scope]['ids'][$numericId] = $numericId;
            }
        }
        $rows = [];
        foreach ($groups as $group) {
            $scope = $group['record'];
            foreach ($this->database->table('estimate_generation_evidence')
                ->where('organization_id', $scope->organizationId)
                ->where('project_id', $scope->projectId)
                ->where('session_id', $scope->sessionId)
                ->whereIn('id', array_values($group['ids']))
                ->whereNull('invalidated_at')
                ->get(['id', 'source_version', 'invalidation_version']) as $row) {
                $rows[$scope->organizationId.':'.$scope->projectId.':'.$scope->sessionId.':evidence:'.$row->id] = $row;
            }
        }
        foreach ($facts as $fact) {
            foreach ($fact->evidenceIds as $evidenceId) {
                if (! isset($rows[$this->evidenceMapKey($fact, $evidenceId)])) {
                    throw new InvalidArgumentException('Project model fact evidence is outside the requested scope or inactive.');
                }
            }
        }

        return $rows;
    }

    private function evidenceRowsForLinks(array $links): array
    {
        $groups = [];
        foreach ($links as $link) {
            $this->assertCrossDocumentLink($link);
            $scopeKey = $link['organization_id'].':'.$link['project_id'].':'.$link['session_id'];
            $groups[$scopeKey]['scope'] = $link;
            foreach (['left', 'right'] as $side) {
                foreach ($link['evidence'][$side] as $evidenceId) {
                    $numericId = $this->evidenceDatabaseId($evidenceId);
                    $groups[$scopeKey]['ids'][$numericId] = $numericId;
                }
            }
            foreach ($link['candidate_evidence_ids'] ?? [] as $evidenceId) {
                $numericId = $this->evidenceDatabaseId($evidenceId);
                $groups[$scopeKey]['ids'][$numericId] = $numericId;
            }
        }
        $rows = [];
        foreach ($groups as $group) {
            $scope = $group['scope'];
            foreach ($this->database->table('estimate_generation_evidence')
                ->where('organization_id', $scope['organization_id'])
                ->where('project_id', $scope['project_id'])
                ->where('session_id', $scope['session_id'])
                ->whereIn('id', array_values($group['ids']))
                ->whereNull('invalidated_at')
                ->get(['id']) as $row) {
                $rows[$scope['organization_id'].':'.$scope['project_id'].':'.$scope['session_id'].':evidence:'.$row->id] = $row;
            }
        }
        foreach ($links as $link) {
            foreach ([
                ...$link['evidence']['left'],
                ...$link['evidence']['right'],
                ...($link['candidate_evidence_ids'] ?? []),
            ] as $evidenceId) {
                if (! isset($rows[$this->linkEvidenceMapKey($link, $evidenceId)])) {
                    throw new InvalidArgumentException('Cross-document link evidence is outside the requested scope or inactive.');
                }
            }
        }

        return $rows;
    }

    private function factIdsForLinks(array $links): array
    {
        $groups = [];
        foreach ($links as $link) {
            $this->assertCrossDocumentLink($link);
            $scopeKey = $this->linkScopeKey($link);
            $groups[$scopeKey]['scope'] = $link;
            $groups[$scopeKey]['ids'][$link['left_fact_id']] = $link['left_fact_id'];
            $groups[$scopeKey]['ids'][$link['right_fact_id']] = $link['right_fact_id'];
            foreach ($link['candidate_fact_ids'] ?? [] as $factId) {
                $groups[$scopeKey]['ids'][$factId] = $factId;
            }
        }
        $factIds = [];
        foreach ($groups as $scopeKey => $group) {
            $scope = $group['scope'];
            foreach ($this->database->table('estimate_generation_project_model_fact_projections as projection')
                ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
                ->where('projection.organization_id', $scope['organization_id'])
                ->where('projection.project_id', $scope['project_id'])
                ->where('projection.session_id', $scope['session_id'])
                ->where('projection.is_current', true)
                ->whereIn('fact.stable_key', array_values($group['ids']))
                ->get(['fact.id', 'fact.stable_key']) as $row) {
                $factIds[$scopeKey.':'.$row->stable_key] = (int) $row->id;
            }
            foreach ($group['ids'] as $stableKey) {
                if (! isset($factIds[$scopeKey.':'.$stableKey])) {
                    throw new InvalidArgumentException('Cross-document link fact is outside the requested scope.');
                }
            }
        }

        return $factIds;
    }

    private function assertCrossDocumentLink(mixed $link): void
    {
        if (! is_array($link) || array_is_list($link)
            || ! is_int($link['organization_id'] ?? null) || ! is_int($link['project_id'] ?? null)
            || ! is_int($link['session_id'] ?? null) || ! is_string($link['source_version'] ?? null)
            || ! is_string($link['id'] ?? null) || ! is_string($link['left_fact_id'] ?? null)
            || ! is_string($link['right_fact_id'] ?? null) || ! is_string($link['strategy'] ?? null)
            || ! is_string($link['match_key'] ?? null) || trim($link['match_key']) === '' || strlen($link['match_key']) > 1000
            || ! is_string($link['reason'] ?? null) || trim($link['reason']) === '' || strlen($link['reason']) > 1000
            || ! is_int($link['strategy_version'] ?? null) || $link['strategy_version'] <= 0
            || ! is_string($link['operation_identity'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $link['operation_identity']) !== 1
            || ! in_array($link['status'] ?? null, ['linked', 'suggested', 'unresolved'], true)
            || ! is_array($link['evidence'] ?? null)
            || array_keys($link['evidence']) !== ['left', 'right']) {
            throw new InvalidArgumentException('Cross-document link batch is invalid.');
        }
        ProjectModelInvariant::scope(
            $link['organization_id'],
            $link['project_id'],
            $link['session_id'],
            $link['source_version'],
        );
        ProjectModelInvariant::id($link['id'], 'Cross-document link');
        ProjectModelInvariant::id($link['left_fact_id'], 'Cross-document left fact');
        ProjectModelInvariant::id($link['right_fact_id'], 'Cross-document right fact');
        if ($link['left_fact_id'] === $link['right_fact_id']
            || ! in_array($link['strategy'], [
                'stable_key',
                'room_number',
                'axes',
                'native_id',
                'equipment_position',
                'facade_material',
                'ai_arbitration',
            ], true)) {
            throw new InvalidArgumentException('Cross-document link batch is invalid.');
        }
        ProjectModelInvariant::uniqueIds($link['evidence']['left'], 'Cross-document left evidence');
        ProjectModelInvariant::uniqueIds($link['evidence']['right'], 'Cross-document right evidence');
        $candidateFactIds = ProjectModelInvariant::uniqueIds(
            $link['candidate_fact_ids'] ?? [$link['left_fact_id'], $link['right_fact_id']],
            'Cross-document candidate fact',
        );
        ProjectModelInvariant::uniqueIds(
            $link['candidate_evidence_ids'] ?? [...$link['evidence']['left'], ...$link['evidence']['right']],
            'Cross-document candidate evidence',
            true,
        );
        if (! in_array($link['left_fact_id'], $candidateFactIds, true)
            || ! in_array($link['right_fact_id'], $candidateFactIds, true)) {
            throw new InvalidArgumentException('Cross-document link candidates are incomplete.');
        }
    }

    private function linkEvidenceMapKey(array $link, string $evidenceId): string
    {
        return $link['organization_id'].':'.$link['project_id'].':'.$link['session_id'].':'.$evidenceId;
    }

    private function linkFactMapKey(array $link, string $factId): string
    {
        return $this->linkScopeKey($link).':'.$factId;
    }

    private function linkScopeKey(array $link): string
    {
        return $link['organization_id'].':'.$link['project_id'].':'.$link['session_id'].':'.$link['source_version'];
    }

    private function evidenceMapKey(Fact $fact, string $evidenceId): string
    {
        return $fact->organizationId.':'.$fact->projectId.':'.$fact->sessionId.':'.$evidenceId;
    }

    private function evidenceDatabaseId(string $evidenceId): int
    {
        if (preg_match('/^evidence:([1-9][0-9]*)$/D', $evidenceId, $matches) !== 1) {
            throw new InvalidArgumentException('Project model evidence identifier is invalid.');
        }

        return (int) $matches[1];
    }

    private function assertChunkSize(int $chunkSize): void
    {
        if ($chunkSize < 1 || $chunkSize > 1000) {
            throw new InvalidArgumentException('Project model chunk size is invalid.');
        }
    }

    private function assertReadLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 10_000) {
            throw new InvalidArgumentException('Project model read limit is invalid.');
        }
    }

    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new InvalidArgumentException('Project model value cannot be serialized.');
        }
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
