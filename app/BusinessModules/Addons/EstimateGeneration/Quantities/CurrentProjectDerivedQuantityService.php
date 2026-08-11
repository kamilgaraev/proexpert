<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;

final readonly class CurrentProjectDerivedQuantityService
{
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
            return $this->blocked('quantity_fact_budget_exceeded', $capture['token'], $technology, $completeness);
        }
        if (! $this->isCurrentStageFive($capture['token'], $technology, $completeness)) {
            return $this->blocked('stage5_projection_not_current', $capture['token'], $technology, $completeness);
        }

        $decisionIds = array_values(array_unique(array_filter($decisionIds, 'is_string')));
        if (count($decisionIds) > 256) {
            return $this->blocked('quantity_decision_budget_exceeded', $capture['token'], $technology, $completeness);
        }
        $assumptionFactIds = array_values(array_map(
            static fn (Fact $fact): string => $fact->id,
            array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->origin === 'user_assumption'),
        ));
        if (count($assumptionFactIds) > 100) {
            return $this->blocked('quantity_decision_budget_exceeded', $capture['token'], $technology, $completeness);
        }
        $decisions = [
            ...$this->models->decisions($organizationId, $projectId, $sessionId, $decisionIds),
            ...$this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, $assumptionFactIds),
        ];
        $decisions = $this->uniqueDecisions($decisions);
        $requests = $this->requests($snapshot, $capture['token'], $technology, $completeness, $decisions);
        if (count($requests) > self::MAX_FORMULAS) {
            return $this->blocked('quantity_formula_budget_exceeded', $capture['token'], $technology, $completeness);
        }

        $derived = [];
        $quantities = [];
        $warnings = [];
        foreach ($requests as $request) {
            $readiness = $this->factory->derive($snapshot, $decisions, $request);
            if (! $readiness->isReady()) {
                $warnings[] = [
                    'code' => 'canonical_quantity_unresolved',
                    'formula' => $request['formula_identity'],
                    'entity_id' => $request['entity_id'],
                    'inputs' => $readiness->unresolvedInputs,
                    'questions' => $readiness->questions,
                ];

                continue;
            }
            $quantity = $readiness->quantity;
            if (! $quantity instanceof DerivedQuantity || $quantity->value === null) {
                continue;
            }
            $derived[] = $quantity;
        }
        if ($derived !== []) {
            $this->models->appendDerivedQuantities($derived, 200);
        }
        $quantities = $this->quantityDataMap($derived, $capture['token'], $warnings);

        return [
            'quantities' => $quantities,
            'warnings' => $warnings,
            'context' => [
                ...$this->context($capture['token'], $technology, $completeness),
                'work_packages' => $this->workPackageContext($completeness, $requests, $decisions),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function requests(ProjectModelSnapshot $snapshot, string $token, array $technology, array $completeness, array $decisions): array
    {
        $facts = [];
        foreach ($snapshot->facts as $fact) {
            $facts[$fact->entityId][$fact->type][] = $fact;
        }
        $openingsByWall = [];
        $roofChildrenByRoof = [];
        foreach ($snapshot->entities as $entity) {
            $semanticType = (string) ($entity->attributes['semantic_type'] ?? $entity->type);
            $wallId = $entity->attributes['wall_id'] ?? null;
            if ($semanticType === 'opening' && is_string($wallId)) {
                $openingsByWall[$wallId][] = $entity;
            }
            $roofId = $entity->attributes['roof_id'] ?? null;
            if (in_array($semanticType, ['roof_facet', 'roof_opening'], true) && is_string($roofId)) {
                $roofChildrenByRoof[$roofId][] = $entity;
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
            $semanticType = (string) ($entity->attributes['semantic_type'] ?? $entity->type);
            if ($semanticType === 'room') {
                $request = $this->binaryRequest($entity, $facts, 'floor_area', 'length', 'width', $token, $technology, $completeness, 'm2', 2);
            } elseif ($semanticType === 'wall') {
                $request = $this->wallRequest(
                    $entity,
                    $openingsByWall[$entity->id] ?? [],
                    $facts,
                    $token,
                    $technology,
                    $completeness,
                );
            } elseif ($semanticType === 'roof') {
                $request = $this->roofRequest(
                    $entity,
                    $roofChildrenByRoof[$entity->id] ?? [],
                    $facts,
                    $token,
                    $technology,
                    $completeness,
                );
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
        foreach ($openings as $opening) {
            $width = $this->oneFact($facts, $opening->id, 'width');
            $openingHeight = $this->oneFact($facts, $opening->id, 'height');
            if ($width instanceof Fact && $openingHeight instanceof Fact) {
                $widths[] = $width->id;
                $heights[] = $openingHeight->id;
            }
        }

        return $this->request('wall_net_area', $wall->id, [
            'wall_length' => $length?->id,
            'wall_height' => $height?->id,
            'opening_widths' => $widths,
            'opening_heights' => $heights,
        ], $token, $technology, $completeness, 'm2', 2);
    }

    /** @param list<Entity> $children @param array<string, array<string, list<Fact>>> $facts */
    private function roofRequest(Entity $roof, array $children, array $facts, string $token, array $technology, array $completeness): array
    {
        $areas = $rises = $runs = $openingAreas = [];
        foreach ($children as $facet) {
            $semanticType = (string) ($facet->attributes['semantic_type'] ?? $facet->type);
            if ($semanticType === 'roof_opening') {
                $openingArea = $this->oneFact($facts, $facet->id, 'area');
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

        return $this->request('sloped_roof_area', $roof->id, $operands, $token, $technology, $completeness, 'm2', 2);
    }

    /** @param array<string, array<string, list<Fact>>> $facts */
    private function oneFact(array $facts, string $entityId, string $type): ?Fact
    {
        $matches = $facts[$entityId][$type] ?? [];

        return count($matches) === 1 ? $matches[0] : null;
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
                'artifact_hash' => hash('sha256', $token."\0".$technology['catalog_hash']."\0".$completeness['rule_catalog_hash']),
                'catalog_version' => $technology['catalog_version'],
                'catalog_hash' => $technology['catalog_hash'],
                'rule_version' => $completeness['rule_catalog_version'],
                'rule_hash' => $completeness['rule_catalog_hash'],
            ],
            'limits' => ['max_operands' => 128, 'max_evidence' => 256, 'max_metadata_bytes' => 65536],
        ];
    }

    private function quantityDataMap(array $derived, string $modelVersion, array &$warnings): array
    {
        $byFormula = [];
        foreach ($derived as $quantity) {
            if ($quantity instanceof DerivedQuantity) {
                $byFormula[$quantity->formulaIdentity ?? $quantity->formula][] = $quantity;
            }
        }
        $aliases = [
            'floor_area' => 'floor_area',
            'wall_net_area' => 'net_wall_area',
            'sloped_roof_area' => 'roof_area',
            'earthwork_volume' => 'earthwork_volume',
        ];
        $result = [];
        foreach ($byFormula as $formula => $items) {
            if (isset($aliases[$formula]) && count($items) === 1) {
                $result[$aliases[$formula]] = $this->quantityData($items[0], $modelVersion, $aliases[$formula]);

                continue;
            }
            foreach ($items as $quantity) {
                $result[$quantity->id] = $this->quantityData($quantity, $modelVersion, $quantity->id);
            }
            if (isset($aliases[$formula]) && count($items) > 1) {
                $warnings[] = [
                    'code' => 'canonical_quantity_aggregate_unresolved',
                    'formula' => $formula,
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

    private function quantityData(DerivedQuantity $quantity, string $modelVersion, string $key): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: $quantity->unit,
            amount: (string) $quantity->value,
            formulaKey: $quantity->formulaIdentity ?? $quantity->formula,
            formulaVersion: $quantity->formulaVersion ?? '1',
            formulaInputs: [
                'operands' => $quantity->operands,
                'rounding_policy' => [
                    'mode' => $quantity->roundingMode,
                    'scale' => $quantity->roundingScale,
                    'boundary' => $quantity->roundingBoundary,
                ],
                'snapshot_identity' => $quantity->snapshotIdentity,
                'technology_decision_id' => $quantity->technologyDecisionId,
            ],
            source: QuantitySource::Evidenced,
            evidenceIds: $quantity->evidenceIds,
            modelVersion: $modelVersion,
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
            'context' => $this->context($token, $technology, $comteness),
        ];
    }
}
