<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;

final readonly class CurrentProjectDerivedQuantityService
{
    public const FORMULA_PURPOSES = [
        'floor_area' => 'floor_area',
        'direct_floor_area' => 'floor_area',
        'wall_net_area' => 'net_wall_area',
        'sloped_roof_area' => 'roof_area',
        'earthwork_volume' => 'earthwork_volume',
    ];

    public static function formulaPurpose(string $formula): ?string
    {
        return self::FORMULA_PURPOSES[$formula] ?? FactVocabulary::engineeringFormulas()[$formula]['purpose'] ?? null;
    }

    private const MAX_FACTS = 10000;

    private const MAX_FORMULAS = 2000;

    public function __construct(
        private ProjectModelRepository $models,
        private DerivedQuantityFactory $factory,
    ) {}

    /**
     * @param  list<string>  $decisionIds
     * @return array{quantities: array<string, QuantityData>, warnings: list<array<string, mixed>>, context: array<string, mixed>}
     */
    public function derive(int $organizationId, int $projectId, int $sessionId, array $decisionIds = []): array
    {
        $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, self::MAX_FACTS + 1);
        $snapshot = $capture['snapshot'];
        $technology = $this->models->currentTechnologyRecommendations($organizationId, $projectId, $sessionId);
        $completeness = $this->models->currentCompleteness($organizationId, $projectId, $sessionId);
        if (count($snapshot->facts) > self::MAX_FACTS) {
            return $this->blockedAndDeactivate($organizationId, $projectId, $sessionId, 'quantity_fact_budget_exceeded', $capture['token'], $technology, $completeness);
        }
        if (! $this->isCurrentStageFive($capture['token'], $technology, $completeness)) {
            return $this->blockedAndDeactivate($organizationId, $projectId, $sessionId, 'stage5_projection_not_current', $capture['token'], $technology, $completeness);
        }

        $decisionIds = array_values(array_unique(array_filter($decisionIds, 'is_string')));
        if (count($decisionIds) > 256) {
            return $this->blockedAndDeactivate($organizationId, $projectId, $sessionId, 'quantity_decision_budget_exceeded', $capture['token'], $technology, $completeness);
        }
        $assumptionFactIds = array_values(array_map(
            static fn (Fact $fact): string => $fact->id,
            array_filter($snapshot->facts, static fn (Fact $fact): bool => in_array($fact->origin, ['user_assumption', 'user_input'], true)),
        ));
        if (count($assumptionFactIds) > self::MAX_FACTS) {
            return $this->blockedAndDeactivate($organizationId, $projectId, $sessionId, 'quantity_decision_budget_exceeded', $capture['token'], $technology, $completeness);
        }
        $decisions = [
            ...$this->models->decisions($organizationId, $projectId, $sessionId, $decisionIds),
            ...$this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, $assumptionFactIds),
        ];
        $decisions = $this->uniqueDecisions($decisions);
        $result = $this->calculateProjection($snapshot, $capture['token'], $technology, $completeness, $decisions);
        $this->models->replaceDerivedQuantityProjection(
            $organizationId,
            $projectId,
            $sessionId,
            (string) ($technology['source_version'] ?? $completeness['source_version'] ?? ''),
            $result['derived'],
            $result['inactive_logical_ids'],
            $capture['token'],
        );

        return [
            'quantities' => $result['quantities'],
            'warnings' => $result['warnings'],
            'context' => $result['context'],
        ];
    }

    /**
     * @param  list<Decision>  $decisions
     * @return array{quantities: array<string, QuantityData>, warnings: list<array<string, mixed>>, context: array<string, mixed>}
     */
    public function simulate(
        ProjectModelSnapshot $snapshot,
        string $projectModelToken,
        ?array $technology,
        ?array $completeness,
        array $decisions,
    ): array {
        if (count($snapshot->facts) > self::MAX_FACTS) {
            return $this->blocked('quantity_fact_budget_exceeded', $projectModelToken, $technology, $completeness);
        }
        if (! $this->isCurrentStageFive($projectModelToken, $technology, $completeness)) {
            return $this->blocked('stage5_projection_not_current', $projectModelToken, $technology, $completeness);
        }
        if (count($decisions) > 256) {
            return $this->blocked('quantity_decision_budget_exceeded', $projectModelToken, $technology, $completeness);
        }

        $result = $this->calculateProjection(
            $snapshot,
            $projectModelToken,
            $technology,
            $completeness,
            $this->uniqueDecisions($decisions),
        );

        return [
            'quantities' => $result['quantities'],
            'warnings' => $result['warnings'],
            'context' => $result['context'],
        ];
    }

    public function previewInput(int $organizationId, int $projectId, int $sessionId): array
    {
        $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, self::MAX_FACTS + 1);
        $snapshot = $capture['snapshot'];
        $decisions = $this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, array_column($snapshot->facts, 'id'));
        if (count($snapshot->facts) > self::MAX_FACTS) {
            return ['quantities' => [], 'warnings' => [['code' => 'quantity_fact_budget_exceeded']]];
        }
        $technology = ['catalog_version' => 'input-preview:v1', 'catalog_hash' => hash('sha256', 'input-preview:v1')];
        $completeness = ['rule_catalog_version' => 'input-preview:v1', 'rule_catalog_hash' => hash('sha256', 'input-preview:v1'),
            'geometry_preview' => true, 'findings' => []];
        $result = $this->calculateProjection($snapshot, $capture['token'], $technology, $completeness, $decisions);

        return ['quantities' => $result['quantities'], 'warnings' => $result['warnings']];
    }

    /** Basic formula simulation from user inputs; does not attest technology/completeness or persist a projection. */
    public function simulateInput(ProjectModelSnapshot $snapshot, string $token, array $decisions): array
    {
        if (count($snapshot->facts) > self::MAX_FACTS || count($decisions) > 256) {
            return ['quantities' => [], 'warnings' => [['code' => 'quantity_input_simulation_budget_exceeded']]];
        }
        $technology = ['catalog_version' => 'input-preview:v1', 'catalog_hash' => hash('sha256', 'input-preview:v1')];
        $completeness = ['rule_catalog_version' => 'input-preview:v1', 'rule_catalog_hash' => hash('sha256', 'input-preview:v1'),
            'geometry_preview' => true, 'findings' => []];
        $result = $this->calculateProjection($snapshot, $token, $technology, $completeness, $this->uniqueDecisions($decisions));

        return ['quantities' => $result['quantities'], 'warnings' => $result['warnings']];
    }

    /**
     * @param  list<Decision>  $decisions
     * @return array{derived:list<DerivedQuantity>,inactive_logical_ids:list<string>,quantities:array<string,QuantityData>,warnings:list<array<string,mixed>>,context:array<string,mixed>}
     */
    private function calculateProjection(
        ProjectModelSnapshot $snapshot,
        string $projectModelToken,
        ?array $technology,
        ?array $completeness,
        array $decisions,
    ): array {
        if (GeometryRelationOverlay::factIds($snapshot) !== []) {
            $snapshot = GeometryRelationOverlay::apply($snapshot, $decisions, GeometryInventory::currentDocumentVersions($snapshot));
        }
        $requests = $this->requests($snapshot, $projectModelToken, $technology, $completeness, $decisions);
        if (count($requests) > self::MAX_FORMULAS) {
            return [
                'derived' => [],
                'inactive_logical_ids' => [],
                ...$this->blocked('quantity_formula_budget_exceeded', $projectModelToken, $technology, $completeness),
            ];
        }

        $derived = [];
        $quantities = [];
        $warnings = [];
        $inactiveLogicalIds = [];
        foreach ($requests as $request) {
            $readiness = $this->factory->derive($snapshot, $decisions, $request);
            if (! $readiness->isReady()) {
                $inactiveLogicalIds[] = $request['quantity_id'];
                $warnings[] = [
                    'code' => 'canonical_quantity_unresolved',
                    'logical_id' => $request['quantity_id'],
                    'formula' => $request['formula_identity'],
                    'entity_id' => $request['entity_id'],
                    'inputs' => $readiness->unresolvedInputs,
                    'questions' => [[
                        'code' => $readiness->unresolvedInputs[0]['code'] ?? 'canonical_quantity_unresolved',
                        'message_key' => 'estimate_generation.geometry_coverage_review',
                        'entity_id' => $request['entity_id'],
                    ]],
                ];

                continue;
            }
            $quantity = $readiness->quantity;
            if (! $quantity instanceof DerivedQuantity || $quantity->value === null) {
                continue;
            }
            $derived[] = $quantity;
        }
        $quantities = $this->quantityDataMap($derived, $projectModelToken, $warnings, array_column($snapshot->facts, null, 'id'));

        return [
            'derived' => $derived,
            'inactive_logical_ids' => $inactiveLogicalIds,
            'quantities' => $quantities,
            'warnings' => $warnings,
            'context' => [
                ...$this->context($projectModelToken, $technology, $completeness),
                'room_floor_entity_ids' => $this->roomFloorEntityIds($snapshot, $derived),
                'work_packages' => $this->workPackageContext($completeness, $requests, $decisions),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function requests(ProjectModelSnapshot $snapshot, string $token, array $technology, array $completeness, array $decisions): array
    {
        $facts = [];
        foreach ($snapshot->facts as $fact) {
            $facts[$fact->entityId][mb_strtolower(trim($fact->type))][] = $fact;
        }
        $reviewedRelations = [];
        foreach ($snapshot->facts as $fact) {
            if (is_array($fact->value) && isset($fact->value['representation']['inventory_contract'])
                && is_string($fact->value['relation'] ?? null)) {
                $reviewedRelations[$fact->entityId][$fact->value['relation']] = true;
            }
        }
        $inventoryDocumentVersions = $reviewedRelations === [] ? null : GeometryInventory::currentDocumentVersions($snapshot);
        $currentInventoryEntities = $reviewedRelations === [] ? [] : GeometryInventory::entities($snapshot, $inventoryDocumentVersions);
        $openingsByWall = [];
        $roofChildrenByRoof = [];
        foreach ($snapshot->entities as $entity) {
            $semanticType = (string) ($entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type);
            $wallId = $entity->attributes['wall_id'] ?? null;
            if ($semanticType === 'opening' && is_string($wallId)) {
                if (! isset($reviewedRelations[$wallId]['wall_openings']) || isset($currentInventoryEntities[$entity->id])) {
                    $openingsByWall[$wallId][] = $entity;
                }
            }
            $roofId = $entity->attributes['roof_id'] ?? null;
            if (in_array($semanticType, ['roof_facet', 'roof_opening'], true) && is_string($roofId)) {
                $relation = $semanticType === 'roof_facet' ? 'roof_facets' : 'roof_openings';
                if (! isset($reviewedRelations[$roofId][$relation]) || isset($currentInventoryEntities[$entity->id])) {
                    $roofChildrenByRoof[$roofId][] = $entity;
                }
            }
        }
        foreach ([&$openingsByWall, &$roofChildrenByRoof] as &$childrenByParent) {
            foreach ($childrenByParent as &$children) {
                usort($children, static fn (Entity $left, Entity $right): int => $left->id <=> $right->id);
            }
            unset($children);
        }
        unset($childrenByParent);

        $requests = [];
        foreach ($snapshot->entities as $entity) {
            $semanticType = (string) ($entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type);
            foreach (FactVocabulary::engineeringFormulas() as $formula => $definition) {
                if (! in_array($semanticType, $definition['entities'], true)) {
                    continue;
                }
                $measurement = $this->oneFact($facts, $entity->id, $definition['parameter']);
                $requests[] = $this->request($formula, $entity->id, ['measurement' => $measurement?->id], $token,
                    $technology, $completeness, $definition['unit'], $definition['group'] === 'count' ? 0 : 8);
            }
            if ($semanticType === 'room') {
                $area = $this->oneFact($facts, $entity->id, 'area');
                $request = $area instanceof Fact
                    ? $this->request(
                        'direct_floor_area',
                        $entity->id,
                        ['measurement' => $area->id],
                        $token,
                        $technology,
                        $completeness,
                        'm2',
                        2,
                    )
                    : $this->binaryRequest($entity, $facts, 'floor_area', 'length', 'width', $token, $technology, $completeness, 'm2', 2);
            } elseif ($semanticType === 'wall') {
                $request = $this->wallRequest(
                    $entity,
                    $openingsByWall[$entity->id] ?? [],
                    $facts,
                    $token,
                    $technology,
                    $completeness,
                );
                $this->applyCoverage($request, $facts, $entity, 'wall_openings', count($openingsByWall[$entity->id] ?? []), $snapshot, $decisions, $inventoryDocumentVersions);
            } elseif ($semanticType === 'roof') {
                $roofChildren = $roofChildrenByRoof[$entity->id] ?? [];
                $request = $this->roofRequest(
                    $entity,
                    $roofChildren,
                    $facts,
                    $token,
                    $technology,
                    $completeness,
                );
                $facetCount = count(array_filter($roofChildren, static fn (Entity $child): bool => ($child->attributes['semantic_type'] ?? $child->type) === 'roof_facet'
                ));
                $openingCount = count(array_filter($roofChildren, static fn (Entity $child): bool => ($child->attributes['semantic_type'] ?? $child->type) === 'roof_opening'
                ));
                $this->applyCoverage($request, $facts, $entity, 'roof_facets', $facetCount, $snapshot, $decisions, $inventoryDocumentVersions);
                $this->applyCoverage($request, $facts, $entity, 'roof_openings', $openingCount, $snapshot, $decisions, $inventoryDocumentVersions);
            } elseif ($semanticType === 'site' && $this->requiresSitePreparation($completeness)) {
                $request = $this->binaryRequest($entity, $facts, 'earthwork_volume', 'area', 'depth', $token, $technology, $completeness, 'm3', 3);
            } else {
                $request = null;
            }
            if ($request !== null) {
                $requests[] = $request;
            }
        }
        array_push($requests, ...$this->technologyPackageRequests(
            $snapshot, $token, $technology, $completeness, $decisions,
        ));
        $bindings = new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
        $decisionIndex = array_column($decisions, null, 'selectedFactId');
        $keys = [];
        foreach ($snapshot->facts as $binding) {
            $decision = $decisionIndex[$binding->id] ?? null;
            if (! $decision instanceof Decision) {
                continue;
            }
            $source = $bindings->source($snapshot, $binding, $decision);
            $unit = $source === null ? null : $bindings::canonicalUnit($source->unit);
            if ($source === null || $unit === null) {
                continue;
            }
            $request = $this->request('direct_quantity', $source->entityId, ['measurement' => $source->id], $token, $technology, $completeness, $unit, 8);
            $request['quantity_id'] = $binding->value['quantity_key'];
            $request['quantity_binding_fact_id'] = $binding->id;
            $request['quantity_binding_decision_id'] = $decision->id;
            $requests[] = $request;
            $keys[$request['quantity_id']] = ($keys[$request['quantity_id']] ?? 0) + 1;
        }
        foreach ($requests as &$request) {
            if (($keys[$request['quantity_id']] ?? 0) > 1) {
                $request['preflight_issues'] = [['code' => 'quantity_binding_ambiguous', 'operand' => 'measurement', 'fact_id' => null]];
            }
        }
        unset($request);

        usort($requests, static fn (array $a, array $b): int => [$a['formula_identity'], $a['entity_id']] <=> [$b['formula_identity'], $b['entity_id']]);

        return $requests;
    }

    /** @param array<string, array<string, list<Fact>>> $facts */
    private function binaryRequest(
        Entity $entity,
        array $facts,
        string $formula,
        string $leftRole,
        string $rightRole,
        string $token,
        array $technology,
        array $completeness,
        string $unit,
        int $scale,
    ): array {
        $left = $this->oneFact($facts, $entity->id, $leftRole);
        $right = $this->oneFact($facts, $entity->id, $rightRole);

        return $this->request($formula, $entity->id, [
            $leftRole => $left?->id,
            $rightRole => $right?->id,
        ], $token, $technology, $completeness, $unit, $scale);
    }

    /** @param list<Entity> $openings @param array<string, array<string, list<Fact>>> $facts */
    private function wallRequest(Entity $wall, array $openings, array $facts, string $token, array $technology, array $completeness): array
    {
        $length = $this->oneFact($facts, $wall->id, 'length');
        $height = $this->oneFact($facts, $wall->id, 'height');
        $widths = $heights = [];
        $geometryInputs = [];
        foreach ($openings as $opening) {
            $width = $this->oneFact($facts, $opening->id, 'width');
            $openingHeight = $this->oneFact($facts, $opening->id, 'height');
            $geometryInputs[] = [
                'entity_id' => $opening->id,
                'kind' => 'wall_opening',
                'operands' => [
                    'width' => $width?->id,
                    'height' => $openingHeight?->id,
                ],
            ];
            if ($width instanceof Fact && $openingHeight instanceof Fact) {
                $widths[] = $width->id;
                $heights[] = $openingHeight->id;
            }
        }

        $request = $this->request('wall_net_area', $wall->id, [
            'wall_length' => $length?->id,
            'wall_height' => $height?->id,
            'opening_widths' => $widths,
            'opening_heights' => $heights,
        ], $token, $technology, $completeness, 'm2', 2);
        $request['geometry_inputs'] = $geometryInputs;

        return $request;
    }

    /** @param list<Entity> $children @param array<string, array<string, list<Fact>>> $facts */
    private function roofRequest(Entity $roof, array $children, array $facts, string $token, array $technology, array $completeness): array
    {
        $areas = $rises = $runs = $openingAreas = [];
        $geometryInputs = [];
        foreach ($children as $facet) {
            $semanticType = (string) ($facet->attributes['semantic_type'] ?? $facet->type);
            if ($semanticType === 'roof_opening') {
                $openingArea = $this->oneFact($facts, $facet->id, 'area');
                $geometryInputs[] = [
                    'entity_id' => $facet->id,
                    'kind' => 'roof_opening',
                    'operands' => ['area' => $openingArea?->id],
                ];
                if ($openingArea instanceof Fact) {
                    $openingAreas[] = $openingArea->id;
                }

                continue;
            }
            if ($semanticType !== 'roof_facet' || ($facet->attributes['roof_id'] ?? null) !== $roof->id) {
                continue;
            }
            $area = $this->oneFact($facts, $facet->id, 'plan_area');
            $rise = $this->oneFact($facts, $facet->id, 'slope_rise');
            $run = $this->oneFact($facts, $facet->id, 'slope_run');
            $geometryInputs[] = [
                'entity_id' => $facet->id,
                'kind' => 'roof_facet',
                'operands' => [
                    'plan_area' => $area?->id,
                    'slope_rise' => $rise?->id,
                    'slope_run' => $run?->id,
                ],
            ];
            if ($area instanceof Fact && $rise instanceof Fact && $run instanceof Fact) {
                $areas[] = $area->id;
                $rises[] = $rise->id;
                $runs[] = $run->id;
            }
        }
        $operands = [
            'plan_areas' => $areas,
            'slope_rises' => $rises,
            'slope_runs' => $runs,
        ];
        if ($openingAreas !== []) {
            $operands['roof_opening_areas'] = $openingAreas;
        }

        $request = $this->request('sloped_roof_area', $roof->id, $operands, $token, $technology, $completeness, 'm2', 2);
        $request['geometry_inputs'] = $geometryInputs;

        return $request;
    }

    /** @param array<string, array<string, list<Fact>>> $facts */
    private function oneFact(array $facts, string $entityId, string $type): ?Fact
    {
        $matches = [];
        foreach (\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::aliases($type) as $alias) {
            $matches = [...$matches, ...($facts[$entityId][$alias] ?? [])];
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, array<string, list<Fact>>> $facts */
    private function applyCoverage(array &$request, array $facts, Entity $entity, string $relation, int $entityCount, ProjectModelSnapshot $snapshot, array $decisions, ?array $inventoryDocumentVersions = null): void
    {
        $coverageFacts = array_values(array_filter(
            [
                ...($facts[$entity->id]['geometry_coverage'] ?? []),
                ...($facts[$entity->id]['geometry_coverage_'.$relation] ?? []),
            ],
            static fn (Fact $fact): bool => is_array($fact->value) && ($fact->value['relation'] ?? null) === $relation,
        ));
        if (count($coverageFacts) !== 1) {
            $request['preflight_issues'][] = [
                'code' => 'geometry_coverage_unknown',
                'operand' => 'geometry_coverage',
                'entity_id' => $entity->id,
                'relation' => $relation,
            ];

            return;
        }
        $fact = $coverageFacts[0];
        $coverage = GeometryCoverage::fromFact($fact, $relation);
        if (! $coverage instanceof GeometryCoverage || ! $this->coverageConfirmed($fact, $snapshot, $decisions)) {
            $request['preflight_issues'][] = [
                'code' => 'geometry_coverage_blocked',
                'operand' => 'geometry_coverage',
                'entity_id' => $entity->id,
                'relation' => $relation,
            ];

            return;
        }
        if (isset($coverage->representation['inventory_contract'])) {
            try {
                $inventory = GeometryInventory::capture($snapshot, $entity->id, $relation, $inventoryDocumentVersions);
                $current = $coverage->representation['inventory_contract'] === 'geometry-inventory:v1'
                    && is_string($coverage->representation['inventory_fingerprint'] ?? null)
                    && hash_equals($inventory['fingerprint'], $coverage->representation['inventory_fingerprint']);
            } catch (\InvalidArgumentException) {
                $current = false;
            }
            if (! $current) {
                $request['preflight_issues'][] = ['code' => 'geometry_coverage_blocked', 'operand' => 'geometry_coverage',
                    'entity_id' => $entity->id, 'relation' => $relation, 'fact_id' => $fact->id, 'coverage_status' => 'stale'];

                return;
            }
        }
        $issue = $coverage->issue($fact->status, $entityCount);
        if ($issue !== null) {
            $request['preflight_issues'][] = [...$issue, 'entity_id' => $entity->id, 'relation' => $relation];

            return;
        }
        $request['geometry_coverages'][] = $coverage->identity();
        $request['snapshot']['geometry_coverages'][] = $coverage->identity();
    }

    private function coverageConfirmed(Fact $fact, ProjectModelSnapshot $snapshot, array $decisions): bool
    {
        return GeometryCoverage::isUserConfirmed($fact, $snapshot, $decisions);
    }

    /** @return list<Decision> */
    private function uniqueDecisions(array $decisions): array
    {
        $unique = [];
        foreach ($decisions as $decision) {
            if ($decision instanceof Decision) {
                $unique[$decision->id] = $decision;
            }
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    private function requiresSitePreparation(array $completeness): bool
    {
        if (($completeness['geometry_preview'] ?? false) === true) {
            return true;
        }
        foreach ($completeness['findings'] ?? [] as $finding) {
            if ($finding instanceof CompletenessFinding && $finding->ruleId === 'site_leveling'
                && in_array($finding->status, ['unknown', 'proven_missing'], true)
                && $finding->workPackage !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function workPackageContext(array $completeness, array $requests, array $decisions): array
    {
        $requestsByQuantity = [];
        foreach ($requests as $request) {
            if (is_string($request['quantity_id'] ?? null)) {
                $requestsByQuantity[$request['quantity_id']] = $request;
            }
        }
        $decisionsById = [];
        foreach ($decisions as $decision) {
            if ($decision instanceof Decision) {
                $decisionsById[$decision->id] = $decision;
            }
        }

        $packages = [];
        foreach ($completeness['findings'] ?? [] as $finding) {
            if (! $finding instanceof CompletenessFinding || $finding->workPackage === null
                || ! in_array($finding->status, ['unknown', 'proven_missing'], true)
                || count($packages) >= 200) {
                continue;
            }
            $quantities = [];
            $technologyDecision = null;
            foreach ($finding->workPackage->quantityFormulas as $formula) {
                $key = 'quantity:technology_work_package:'.$finding->workPackage->id.':'.($formula['id'] ?? 'unknown');
                $request = $requestsByQuantity[$key] ?? [];
                $decisionId = $request['technology_decision_id'] ?? null;
                $decision = is_string($decisionId) ? ($decisionsById[$decisionId] ?? null) : null;
                if ($decision instanceof Decision) {
                    $technologyDecision = [
                        'id' => $decision->id,
                        'version' => $decision->version,
                        'status' => 'current',
                        'actor_id' => $decision->actorId,
                        'provenance' => ['selected_fact_id' => $decision->selectedFactId],
                    ];
                }
                $quantities[] = [
                    'key' => $key,
                    'formula_id' => $formula['id'] ?? null,
                    'unit' => $formula['unit'] ?? null,
                ];
            }
            $packages[] = [
                'id' => $finding->workPackage->id,
                'finding_key' => $finding->stableKey,
                'finding_version' => $finding->version,
                'status' => $finding->status,
                'works' => $finding->workPackage->works,
                'norm_intents' => $finding->workPackage->normIntents,
                'quantities' => $quantities,
                'evidence_fact_ids' => $finding->evidenceFactIds,
                'technology_decision' => $technologyDecision,
                'completeness_decision' => $finding->exclusionDecision,
            ];
        }

        return $packages;
    }

    /** @return list<array<string, mixed>> */
    private function technologyPackageRequests(
        ProjectModelSnapshot $snapshot,
        string $token,
        array $technology,
        array $completeness,
        array $decisions,
    ): array {
        $factsById = [];
        foreach ($snapshot->facts as $fact) {
            $factsById[$fact->id] = $fact;
        }
        $decisionByFact = [];
        foreach ($decisions as $decision) {
            if ($decision instanceof Decision && $decision->actorType === 'user' && $decision->selectedFactId !== null) {
                $decisionByFact[$decision->selectedFactId] = $decision;
            }
        }
        $selectedSystems = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->status === 'confirmed' && $fact->origin === 'user_assumption' && is_array($fact->value)
                && ($fact->value['kind'] ?? null) === 'catalog_system'
                && ($fact->value['catalog_version'] ?? null) === ($technology['catalog_version'] ?? null)
                && ($fact->value['catalog_hash'] ?? null) === ($technology['catalog_hash'] ?? null)
                && isset($decisionByFact[$fact->id])) {
                $selectedSystems[] = [$fact, $decisionByFact[$fact->id]];
            }
        }

        $requests = [];
        foreach ($completeness['findings'] ?? [] as $finding) {
            if (! $finding instanceof CompletenessFinding || $finding->workPackage === null
                || ! in_array($finding->status, ['unknown', 'proven_missing'], true)) {
                continue;
            }
            foreach ($finding->workPackage->quantityFormulas as $formula) {
                $operand = is_array($formula['operands'][0] ?? null) ? $formula['operands'][0] : [];
                $base = is_string($operand['fact_id'] ?? null) ? ($factsById[$operand['fact_id']] ?? null) : null;
                if (! $base instanceof Fact) {
                    continue;
                }
                $systems = array_values(array_filter(
                    $selectedSystems,
                    static fn (array $selected): bool => $selected[0]->entityId === $base->entityId,
                ));
                if ($systems === [] && count($selectedSystems) === 1) {
                    $systems = $selectedSystems;
                }
                $systemFact = count($systems) === 1 ? $systems[0][0] : null;
                $decision = count($systems) === 1 ? $systems[0][1] : null;
                $request = $this->request(
                    'technology_work_package',
                    $base->entityId,
                    ['base_quantity' => $base->id],
                    $token,
                    $technology,
                    $completeness,
                    (string) ($formula['unit'] ?? ''),
                    2,
                );
                $request['quantity_id'] = 'quantity:technology_work_package:'.$finding->workPackage->id.':'.($formula['id'] ?? 'unknown');
                $request['formula_version'] = $finding->ruleVersion.':'.($formula['id'] ?? 'unknown');
                $request['technology_operation'] = 'identity';
                $request['technology_decision_id'] = $decision?->id;
                $request['applicable_system_id'] = is_array($systemFact?->value) ? ($systemFact->value['system_id'] ?? null) : null;
                $request['technology_status'] = 'current';
                $request['technology_applicable'] = $systemFact instanceof Fact;
                $request['technology_availability'] = $systemFact instanceof Fact ? 'available' : 'unresolved';
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /** @param array<string, string|list<string>> $operands @return array<string, mixed> */
    private function request(
        string $formula,
        string $entityId,
        array $operands,
        string $token,
        array $technology,
        array $completeness,
        string $unit,
        int $scale,
    ): array {
        return [
            'quantity_id' => 'quantity:'.$formula.':'.$entityId,
            'formula_identity' => $formula,
            'formula_version' => '1',
            'entity_id' => $entityId,
            'operands' => $operands,
            'output_unit' => $unit,
            'rounding_mode' => 'half_up',
            'rounding_scale' => $scale,
            'snapshot' => [
                'input_fingerprint' => $token,
                'artifact_hash' => hash('sha256', json_encode([
                    'input_fingerprint' => $token,
                    'technology_run_id' => $technology['run_id'] ?? null,
                    'technology_input_fingerprint' => $technology['input_fingerprint'] ?? null,
                    'technology_source_version' => $technology['source_version'] ?? null,
                    'catalog_version' => $technology['catalog_version'] ?? null,
                    'catalog_hash' => $technology['catalog_hash'] ?? null,
                    'completeness_run_id' => $completeness['run_id'] ?? null,
                    'completeness_input_fingerprint' => $completeness['input_fingerprint'] ?? null,
                    'completeness_source_version' => $completeness['source_version'] ?? null,
                    'rule_version' => $completeness['rule_catalog_version'] ?? null,
                    'rule_hash' => $completeness['rule_catalog_hash'] ?? null,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'technology_run_id' => $technology['run_id'] ?? null,
                'technology_input_fingerprint' => $technology['input_fingerprint'] ?? null,
                'technology_source_version' => $technology['source_version'] ?? null,
                'completeness_run_id' => $completeness['run_id'] ?? null,
                'completeness_input_fingerprint' => $completeness['input_fingerprint'] ?? null,
                'completeness_source_version' => $completeness['source_version'] ?? null,
                'catalog_version' => $technology['catalog_version'],
                'catalog_hash' => $technology['catalog_hash'],
                'rule_version' => $completeness['rule_catalog_version'],
                'rule_hash' => $completeness['rule_catalog_hash'],
            ],
            'limits' => [
                'max_operands' => 128,
                'max_geometry_entities' => 128,
                'max_physical_references' => 256,
                'max_physical_reference_bytes' => 65536,
                'max_evidence' => 256,
                'max_metadata_bytes' => 65536,
            ],
        ];
    }

    private function quantityDataMap(array $derived, string $modelVersion, array &$warnings, array $facts): array
    {
        $aliases = [...self::FORMULA_PURPOSES, ...array_map(static fn (array $definition): string => $definition['purpose'], FactVocabulary::engineeringFormulas())];
        $aliasedPurposes = array_fill_keys(array_values($aliases), true);
        $unresolvedPurposes = [];
        foreach ($warnings as $warning) {
            if (is_string($warning['formula'] ?? null)) {
                $unresolvedPurposes[$aliases[$warning['formula']] ?? $warning['formula']] = true;
            }
        }
        $byPurpose = [];
        foreach ($derived as $quantity) {
            if ($quantity instanceof DerivedQuantity) {
                $formula = $quantity->formulaIdentity ?? $quantity->formula;
                $byPurpose[$aliases[$formula] ?? $formula][] = $quantity;
            }
        }
        $result = [];
        foreach ($byPurpose as $purpose => $items) {
            if (isset($aliasedPurposes[$purpose]) && count($items) === 1 && ! isset($unresolvedPurposes[$purpose])) {
                $result[$purpose] = $this->quantityData($items[0], $modelVersion, $purpose, $facts);

                continue;
            }
            foreach ($items as $quantity) {
                $key = $quantity->logicalId ?? $quantity->id;
                $result[$key] = $this->quantityData($quantity, $modelVersion, $key, $facts);
            }
            if (isset($aliasedPurposes[$purpose]) && count($items) > 1) {
                $formulas = array_values(array_unique(array_map(
                    static fn (DerivedQuantity $quantity): string => $quantity->formulaIdentity ?? $quantity->formula, $items)));
                $warnings[] = [
                    'code' => 'canonical_quantity_aggregate_unresolved',
                    'formula' => count($formulas) === 1 ? $formulas[0] : $purpose,
                    'quantity_ids' => array_slice(array_map(
                        static fn (DerivedQuantity $quantity): string => $quantity->id,
                        $items,
                    ), 0, 100),
                ];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function roomFloorEntityIds(ProjectModelSnapshot $snapshot, array $derived): array
    {
        $entities = array_column($snapshot->entities, null, 'id');
        $rooms = [];
        foreach ($derived as $quantity) {
            $entity = $entities[$quantity->entityId] ?? null;
            if ($entity instanceof Entity && self::formulaPurpose($quantity->formulaIdentity ?? $quantity->formula) === 'floor_area'
                && ($entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type) === 'room'
                && ($entity->attributes['document_role'] ?? $entity->attributes['properties']['document_role'] ?? null) !== 'building_floor') {
                $rooms[$entity->id] = true;
            }
        }
        ksort($rooms, SORT_STRING);

        return array_keys($rooms);
    }

    private function quantityData(DerivedQuantity $quantity, string $modelVersion, string $key, array $facts): QuantityData
    {
        $assumptions = [];
        foreach ($quantity->operands as $operand) {
            if (($facts[$operand['fact_id']]->origin ?? null) === 'user_assumption') {
                $assumptions[] = $operand['fact_id'];
            }
        }

        return new QuantityData(
            key: $key,
            unit: $quantity->unit,
            amount: (string) $quantity->value,
            formulaKey: $quantity->formulaIdentity ?? $quantity->formula,
            formulaVersion: $quantity->formulaVersion ?? '1',
            formulaInputs: [
                'entity_id' => $quantity->entityId,
                'operands' => $quantity->operands,
                'rounding_policy' => [
                    'mode' => $quantity->roundingMode,
                    'scale' => $quantity->roundingScale,
                    'boundary' => $quantity->roundingBoundary,
                ],
                'snapshot_identity' => $quantity->snapshotIdentity,
                'technology_decision_id' => $quantity->technologyDecisionId,
            ],
            source: $assumptions === [] ? QuantitySource::Evidenced : QuantitySource::Estimated,
            evidenceIds: $quantity->evidenceIds,
            modelVersion: $modelVersion,
            assumptions: array_values(array_unique($assumptions)),
        );
    }

    private function isCurrentStageFive(string $token, ?array $technology, ?array $completeness): bool
    {
        return is_array($technology)
            && is_array($completeness)
            && ($technology['is_current'] ?? false) === true
            && ($completeness['is_current'] ?? false) === true
            && hash_equals($token, (string) ($technology['input_fingerprint'] ?? ''))
            && hash_equals($token, (string) ($completeness['input_fingerprint'] ?? ''))
            && hash_equals((string) ($technology['source_version'] ?? ''), (string) ($completeness['source_version'] ?? ''))
            && hash_equals((string) ($technology['catalog_version'] ?? ''), (string) ($completeness['catalog_version'] ?? ''))
            && hash_equals((string) ($technology['catalog_hash'] ?? ''), (string) ($completeness['catalog_hash'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function context(string $token, ?array $technology, ?array $completeness): array
    {
        return [
            'input_snapshot_hash' => $token,
            'source_version' => $technology['source_version'] ?? $completeness['source_version'] ?? null,
            'technology_identity' => [
                'version' => $technology['catalog_version'] ?? null,
                'hash' => $technology['catalog_hash'] ?? null,
                'run_id' => $technology['run_id'] ?? null,
                'status' => ($technology['is_current'] ?? false) ? 'current' : 'stale',
            ],
            'rule_identity' => [
                'version' => $completeness['rule_catalog_version'] ?? null,
                'hash' => $completeness['rule_catalog_hash'] ?? null,
                'run_id' => $completeness['run_id'] ?? null,
                'status' => ($completeness['is_current'] ?? false) ? 'current' : 'stale',
            ],
        ];
    }

    /** @return array{quantities: array<string, QuantityData>, warnings: list<array<string, mixed>>, context: array<string, mixed>} */
    private function blocked(string $code, string $token, ?array $technology, ?array $completeness): array
    {
        return [
            'quantities' => [],
            'warnings' => [['code' => $code]],
            'context' => $this->context($token, $technology, $completeness),
        ];
    }

    private function blockedAndDeactivate(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $code,
        string $token,
        ?array $technology,
        ?array $completeness,
    ): array {
        $this->models->deactivateDerivedQuantityProjectionScope(
            $organizationId,
            $projectId,
            $sessionId,
            stageFiveOnly: true,
        );

        return $this->blocked($code, $token, $technology, $completeness);
    }
}
