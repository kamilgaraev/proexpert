<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;

final readonly class EloquentProjectModelRepository implements ProjectModelRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function appendEntities(array $entities, int $chunkSize = 500): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($entities, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                foreach ($chunk as $entity) {
                    if (! $entity instanceof Entity) {
                        throw new InvalidArgumentException('Project model entity batch is invalid.');
                    }
                    $buildingModelId = $this->buildingModelId($entity);
                    $this->database->table('estimate_generation_project_model_entities')->insertOrIgnore([
                        'building_model_id' => $buildingModelId,
                        'organization_id' => $entity->organizationId,
                        'project_id' => $entity->projectId,
                        'session_id' => $entity->sessionId,
                        'source_version' => $entity->sourceVersion,
                        'stable_key' => $entity->stableKey,
                        'entity_kind' => $entity->type,
                        'payload' => $this->json([
                            'kind' => $entity->type,
                            'key' => $entity->stableKey,
                            ...$entity->attributes,
                        ]),
                        'confidence' => null,
                        'created_at' => now(),
                    ]);
                }
            }, 3);
        }
    }

    public function appendFacts(array $facts, int $chunkSize = 500): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($facts, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                $evidenceRows = $this->evidenceRowsForFacts($chunk);
                foreach ($chunk as $fact) {
                    if (! $fact instanceof Fact) {
                        throw new InvalidArgumentException('Project model fact batch is invalid.');
                    }
                    $buildingModelId = $this->buildingModelId($fact);
                    $entity = $this->entityRow($fact);
                    $payload = ['source' => $this->legacySource($fact->origin), 'value' => $fact->value];
                    if ($fact->unit !== null) {
                        $payload['unit'] = $fact->unit;
                    }
                    $this->database->table('estimate_generation_project_model_assertions')->insertOrIgnore([
                        'building_model_id' => $buildingModelId,
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
                    if (in_array($fact->status, ['confirmed', 'unresolved'], true)) {
                        $this->projectCurrentFact($fact, $buildingModelId, (int) $entity->id);
                    }
                }
            }, 3);
        }
    }

    public function appendConflicts(array $conflicts, int $chunkSize = 200): void
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

    public function appendDecisions(array $decisions, int $chunkSize = 200): void
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
                    $buildingModelId = $this->buildingModelId($decision);
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
                        'building_model_id' => $buildingModelId,
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
                        'created_at' => now(),
                    ]);
                }
            }, 3);
        }
    }

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void
    {
        $this->assertChunkSize($chunkSize);
        foreach (array_chunk($quantities, $chunkSize) as $chunk) {
            $this->database->connection()->transaction(function () use ($chunk): void {
                foreach ($chunk as $quantity) {
                    if (! $quantity instanceof DerivedQuantity) {
                        throw new InvalidArgumentException('Derived quantity batch is invalid.');
                    }
                    $this->database->table('estimate_generation_project_model_derived_quantities')->insertOrIgnore([
                        'organization_id' => $quantity->organizationId,
                        'project_id' => $quantity->projectId,
                        'session_id' => $quantity->sessionId,
                        'source_version' => $quantity->sourceVersion,
                        'stable_key' => $quantity->id,
                        'entity_stable_key' => $quantity->entityId,
                        'formula' => $quantity->formula,
                        'value' => $quantity->value,
                        'unit' => $quantity->unit,
                        'rounding_mode' => $quantity->roundingMode,
                        'rounding_scale' => $quantity->roundingScale,
                        'status' => $quantity->status,
                        'evidence_lineage' => $this->json($quantity->evidenceIds),
                        'created_at' => now(),
                    ]);
                    $quantityId = $this->database->table('estimate_generation_project_model_derived_quantities')
                        ->where('organization_id', $quantity->organizationId)
                        ->where('project_id', $quantity->projectId)
                        ->where('session_id', $quantity->sessionId)
                        ->where('source_version', $quantity->sourceVersion)
                        ->where('stable_key', $quantity->id)
                        ->value('id');
                    foreach ($quantity->operands as $ordinal => $operand) {
                        $this->database->table('estimate_generation_project_model_derived_operands')->insertOrIgnore([
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
                }
            }, 3);
        }
    }

    public function appendCrossDocumentLinks(array $links, int $chunkSize = 200): void
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
                        'reason' => $link['reason'],
                        'strategy_version' => $link['strategy_version'],
                        'operation_identity' => $link['operation_identity'],
                        'status' => $link['status'],
                        'is_current' => true,
                        'created_at' => now(),
                    ];
                }
                $this->database->table('estimate_generation_project_model_cross_document_links')->insertOrIgnore($linkRows);
                $storedLinks = [];
                foreach ($this->database->table('estimate_generation_project_model_cross_document_links')
                    ->whereIn('operation_identity', array_column($chunk, 'operation_identity'))
                    ->get(['id', 'organization_id', 'project_id', 'session_id', 'source_version', 'operation_identity']) as $row) {
                    $storedLinks[$row->organization_id.':'.$row->project_id.':'.$row->session_id.':'.$row->source_version.':'.$row->operation_identity] = (int) $row->id;
                }
                $evidenceLinks = [];
                foreach ($chunk as $link) {
                    $linkId = $storedLinks[$this->linkScopeKey($link).':'.$link['operation_identity']] ?? null;
                    if ($linkId === null) {
                        throw new InvalidArgumentException('Cross-document link persistence failed.');
                    }
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
            }, 3);
        }
    }

    public function currentFacts(int $organizationId, int $projectId, int $sessionId, ?string $entityId = null): array
    {
        if ($organizationId <= 0 || $projectId <= 0 || $sessionId <= 0) {
            throw new InvalidArgumentException('Project model query scope is invalid.');
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
        $rows = $query->orderBy('entity.stable_key')->orderBy('fact.assertion_type')->get([
            'fact.*', 'entity.stable_key as entity_stable_key',
        ]);
        $evidenceByFact = [];
        $factIds = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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
            );
        }

        return $facts;
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
            $scope = static fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('source_version', $sourceVersion)
                ->where('is_current', true);
            $scope($this->database->table('estimate_generation_project_model_fact_projections'))->update([
                'is_current' => false,
                'replacement_source_version' => $replacementSourceVersion,
                'invalidated_at' => now(),
            ]);
            $scope($this->database->table('estimate_generation_project_model_cross_document_links'))->update([
                'is_current' => false,
                'invalidated_at' => now(),
            ]);
        }, 3);
    }

    private function projectCurrentFact(Fact $fact, int $buildingModelId, int $entityDatabaseId): void
    {
        $factDatabaseId = $this->database->table('estimate_generation_project_model_assertions')
            ->where('building_model_id', $buildingModelId)->where('stable_key', $fact->id)->value('id');
        $current = $this->database->table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as current_fact', 'current_fact.id', '=', 'projection.fact_id')
            ->where('projection.organization_id', $fact->organizationId)
            ->where('projection.project_id', $fact->projectId)
            ->where('projection.session_id', $fact->sessionId)
            ->where('projection.entity_stable_key', $fact->entityId)
            ->where('projection.fact_type', $fact->type)
            ->where('projection.is_current', true)->lockForUpdate()
            ->first(['projection.*', 'current_fact.building_model_id as current_building_model_id']);
        if ($current !== null && (int) $current->fact_id === (int) $factDatabaseId) {
            return;
        }
        if ($current !== null && ((int) $current->current_building_model_id > $buildingModelId
            || ((int) $current->current_building_model_id === $buildingModelId
                && (int) $current->projection_version >= $fact->version))) {
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

    private function buildingModelId(object $record): int
    {
        $id = $this->database->table('estimate_generation_building_models')
            ->where('organization_id', $record->organizationId)->where('project_id', $record->projectId)
            ->where('session_id', $record->sessionId)->where('content_version', $record->sourceVersion)
            ->value('id');
        if (! is_int($id) && ! ctype_digit((string) $id)) {
            throw new InvalidArgumentException('Project model source version is outside the requested scope.');
        }

        return (int) $id;
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
            foreach ([...$link['evidence']['left'], ...$link['evidence']['right']] as $evidenceId) {
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
        }
        $factIds = [];
        foreach ($groups as $scopeKey => $group) {
            $scope = $group['scope'];
            $buildingModelExists = $this->database->table('estimate_generation_building_models')
                ->where('organization_id', $scope['organization_id'])
                ->where('project_id', $scope['project_id'])
                ->where('session_id', $scope['session_id'])
                ->where('content_version', $scope['source_version'])
                ->exists();
            if (! $buildingModelExists) {
                throw new InvalidArgumentException('Cross-document link source version is outside the requested scope.');
            }
            foreach ($this->database->table('estimate_generation_project_model_assertions')
                ->where('organization_id', $scope['organization_id'])
                ->where('project_id', $scope['project_id'])
                ->where('session_id', $scope['session_id'])
                ->where('source_version', $scope['source_version'])
                ->whereIn('stable_key', array_values($group['ids']))
                ->get(['id', 'stable_key']) as $row) {
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
            || ! is_string($link['reason'] ?? null) || trim($link['reason']) === ''
            || ! is_int($link['strategy_version'] ?? null) || $link['strategy_version'] <= 0
            || ! is_string($link['operation_identity'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $link['operation_identity']) !== 1
            || ! in_array($link['status'] ?? null, ['linked', 'suggested'], true)
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
