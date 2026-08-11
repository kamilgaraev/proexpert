<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final class DerivedQuantityFactory
{
    private const FORMULAS = [
        'floor_area' => ['length', 'width'],
        'wall_net_area' => ['wall_length', 'wall_height', 'opening_widths', 'opening_heights'],
        'sloped_roof_area' => ['plan_areas', 'slope_rises', 'slope_runs'],
        'earthwork_volume' => ['area', 'depth'],
        'technology_work_package' => ['base_quantity', 'coefficient'],
    ];

    private const UNIT_GROUPS = [
        'length' => ['mm' => '0.001', 'cm' => '0.01', 'm' => '1'],
        'area' => ['mm2' => '0.000001', 'cm2' => '0.0001', 'm2' => '1'],
        'volume' => ['mm3' => '0.000000001', 'cm3' => '0.000001', 'm3' => '1'],
        'count' => ['count' => '1'],
    ];

    /** @param list<Decision> $decisions @param array<string, mixed> $request */
    public function derive(ProjectModelSnapshot $snapshot, array $decisions, array $request): QuantityReadiness
    {
        $formula = is_string($request['formula_identity'] ?? null) ? $request['formula_identity'] : '';
        $roles = self::FORMULAS[$formula] ?? null;
        if ($roles === null) {
            return $this->unresolved('formula_unknown', 'formula');
        }
        if ($formula === 'sloped_roof_area' && is_array(($request['operands'] ?? [])['roof_opening_areas'] ?? null)
            && $request['operands']['roof_opening_areas'] !== []) {
            $roles[] = 'roof_opening_areas';
        }
        $limits = is_array($request['limits'] ?? null) ? $request['limits'] : [];
        $maxOperands = $this->boundedLimit($limits['max_operands'] ?? null, 128, 1, 1000);
        $maxEvidence = $this->boundedLimit($limits['max_evidence'] ?? null, 256, 1, 2000);
        $maxMetadataBytes = $this->boundedLimit($limits['max_metadata_bytes'] ?? null, 65536, 1024, 1048576);
        $requestedOperands = is_array($request['operands'] ?? null) ? $request['operands'] : [];
        $operandCount = 0;
        foreach ($requestedOperands as $value) {
            $operandCount += is_array($value) ? count($value) : 1;
        }
        if ($operandCount > $maxOperands) {
            return $this->unresolved('operand_budget_exceeded', 'operands');
        }
        if (count($snapshot->evidence) > $maxEvidence) {
            return $this->unresolved('evidence_budget_exceeded', 'evidence');
        }

        $entities = $this->indexEntities($snapshot->entities);
        $facts = $this->indexFacts($snapshot->facts);
        $evidence = $this->indexEvidence($snapshot->evidence);
        $decisionIndex = $this->indexDecisions($decisions);
        $entityId = is_string($request['entity_id'] ?? null) ? $request['entity_id'] : '';
        $target = $entities[$entityId] ?? null;
        if (! $target instanceof Entity) {
            return $this->unresolved('entity_missing', 'entity', $entityId);
        }
        $technologyDecisionId = is_string($request['technology_decision_id'] ?? null)
            ? $request['technology_decision_id'] : null;
        if ($formula === 'technology_work_package') {
            $decision = $technologyDecisionId === null ? null : ($decisionIndex[$technologyDecisionId] ?? null);
            if (! $decision instanceof Decision || $decision->actorType !== 'user'
                || [$decision->organizationId, $decision->projectId, $decision->sessionId, $decision->sourceVersion]
                    !== [$target->organizationId, $target->projectId, $target->sessionId, $target->sourceVersion]
                || $decision->selectedFactId === null || ! isset($facts[$decision->selectedFactId])
                || ($request['technology_status'] ?? null) !== 'current'
                || ($request['technology_applicable'] ?? null) !== true
                || ($request['technology_availability'] ?? null) !== 'available'
                || ! isset($request['applicable_system_id']) || ! is_string($request['applicable_system_id'])
                || trim($request['applicable_system_id']) === '') {
                return $this->unresolved('decision_missing', 'technology_decision', $technologyDecisionId);
            }
        }
        $issues = [];
        $resolved = [];
        foreach ($roles as $role) {
            $ids = $requestedOperands[$role] ?? null;
            $ids = is_array($ids) ? array_values($ids) : [$ids];
            if ($ids === [null] || $ids === [] || in_array(null, $ids, true)) {
                $issues[] = $this->issue('operand_missing', $role);

                continue;
            }
            if (count($ids) !== count(array_unique($ids, SORT_STRING))) {
                $issues[] = $this->issue('duplicate_operand', $role);

                continue;
            }
            foreach ($ids as $id) {
                if (! is_string($id) || ! isset($facts[$id])) {
                    $issues[] = $this->issue('fact_missing', $role, is_string($id) ? $id : null);

                    continue;
                }
                $resolvedOperand = $this->resolveOperand(
                    $facts[$id], $role, $target, $evidence, $decisionIndex, $formula,
                );
                if (isset($resolvedOperand['issue'])) {
                    $issues[] = $resolvedOperand['issue'];

                    continue;
                }
                $resolved[$role][] = $resolvedOperand;
            }
        }
        if ($issues !== []) {
            return $this->issues($issues);
        }

        $scopeIssue = $this->validateGeometryScope($formula, $target, $resolved, $entities);
        if ($scopeIssue !== null) {
            return $this->issues([$scopeIssue]);
        }
        try {
            $value = $this->calculate($formula, $resolved);
        } catch (MathException) {
            return $this->unresolved('decimal_operation_invalid', 'formula');
        }
        if ($value->isLessThanOrEqualTo(0)) {
            return $this->unresolved('negative_quantity', 'formula');
        }
        $roundingMode = is_string($request['rounding_mode'] ?? null) ? $request['rounding_mode'] : 'half_up';
        $roundingScale = is_int($request['rounding_scale'] ?? null) ? $request['rounding_scale'] : 2;
        if (! in_array($roundingMode, ['half_up', 'half_even', 'floor', 'ceil'], true)
            || $roundingScale < 0 || $roundingScale > 12) {
            return $this->unresolved('rounding_policy_invalid', 'rounding');
        }
        $rounded = $value->toScale($roundingScale, $this->roundingMode($roundingMode));
        $flatOperands = [];
        foreach ($roles as $role) {
            foreach ($resolved[$role] as $operand) {
                $flatOperands[] = $operand;
            }
        }
        $evidenceIds = [];
        foreach ($flatOperands as $operand) {
            $evidenceIds = [...$evidenceIds, ...$operand['evidence_ids']];
        }
        $evidenceIds = array_values(array_unique($evidenceIds));
        sort($evidenceIds, SORT_STRING);
        $snapshotIdentity = is_array($request['snapshot'] ?? null) ? $request['snapshot'] : [];
        foreach ($flatOperands as &$operand) {
            $operand['formula_identity'] = $formula;
            $operand['formula_version'] = (string) ($request['formula_version'] ?? '');
            $operand['rounding_policy'] = [
                'mode' => $roundingMode,
                'scale' => $roundingScale,
                'boundary' => $formula === 'sloped_roof_area'
                    ? 'irrational_operation_then_formula_result' : 'formula_result',
            ];
            $operand['snapshot_identity'] = $snapshotIdentity;
            $operand['technology_decision_id'] = $technologyDecisionId;
        }
        unset($operand);
        $metadataBytes = strlen(json_encode([$flatOperands, $snapshotIdentity], JSON_THROW_ON_ERROR));
        if ($metadataBytes > $maxMetadataBytes) {
            return $this->unresolved('metadata_budget_exceeded', 'metadata');
        }
        $unit = is_string($request['output_unit'] ?? null) ? $request['output_unit'] : '';
        if ($unit !== $this->outputUnit($formula, $resolved)) {
            return $this->unresolved('output_unit_incompatible', 'output_unit');
        }

        $quantity = new DerivedQuantity(
            id: (string) ($request['quantity_id'] ?? ''),
            organizationId: $target->organizationId,
            projectId: $target->projectId,
            sessionId: $target->sessionId,
            sourceVersion: $target->sourceVersion,
            entityId: $target->id,
            formula: $formula.':'.(string) ($request['formula_version'] ?? ''),
            operands: $flatOperands,
            value: (string) $rounded,
            unit: $unit,
            roundingMode: $roundingMode,
            roundingScale: $roundingScale,
            evidenceIds: $evidenceIds,
            status: 'confirmed',
            formulaIdentity: $formula,
            formulaVersion: (string) ($request['formula_version'] ?? ''),
            roundingBoundary: $formula === 'sloped_roof_area'
                ? 'irrational_operation_then_formula_result' : 'formula_result',
            unitCompatibility: 'canonical_conversion',
            snapshotIdentity: $snapshotIdentity,
            technologyDecisionId: $technologyDecisionId,
        );

        return new QuantityReadiness($quantity, [], []);
    }

    /** @return array<string, Entity> */
    private function indexEntities(array $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Entity && ! isset($result[$entity->id])) {
                $result[$entity->id] = $entity;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, Fact> */
    private function indexFacts(array $facts): array
    {
        $result = [];
        foreach ($facts as $fact) {
            if ($fact instanceof Fact && ! isset($result[$fact->id])) {
                $result[$fact->id] = $fact;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, Evidence> */
    private function indexEvidence(array $evidence): array
    {
        $result = [];
        foreach ($evidence as $item) {
            if ($item instanceof Evidence && ! isset($result[$item->id])) {
                $result[$item->id] = $item;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @param list<Decision> $decisions @return array<string, Decision> */
    private function indexDecisions(array $decisions): array
    {
        $result = [];
        foreach ($decisions as $decision) {
            if ($decision instanceof Decision && ! isset($result[$decision->id])) {
                $result[$decision->id] = $decision;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, mixed> */
    private function resolveOperand(
        Fact $fact,
        string $role,
        Entity $target,
        array $evidence,
        array $decisions,
        string $formula,
    ): array {
        if ([$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion]
            !== [$target->organizationId, $target->projectId, $target->sessionId, $target->sourceVersion]) {
            return ['issue' => $this->issue('scope_mismatch', $role, $fact->id)];
        }
        if ($fact->status !== 'confirmed') {
            return ['issue' => $this->issue('operand_not_confirmed', $role, $fact->id)];
        }
        if (! is_string($fact->value) && ! is_int($fact->value)) {
            return ['issue' => $this->issue('decimal_value_required', $role, $fact->id)];
        }
        $group = $this->expectedGroup($formula, $role);
        $factor = self::UNIT_GROUPS[$group][$fact->unit ?? ''] ?? null;
        if ($factor === null) {
            return ['issue' => $this->issue('unit_incompatible', $role, $fact->id)];
        }
        try {
            $sourceValue = BigDecimal::of($fact->value);
        } catch (MathException) {
            return ['issue' => $this->issue('decimal_value_required', $role, $fact->id)];
        }
        if ($sourceValue->isLessThanOrEqualTo(0)) {
            return ['issue' => $this->issue('operand_not_positive', $role, $fact->id)];
        }
        $decision = null;
        if ($fact->origin === 'user_assumption') {
            foreach ($decisions as $candidate) {
                if ($candidate->selectedFactId === $fact->id
                    && $candidate->actorType === 'user'
                    && [$candidate->organizationId, $candidate->projectId, $candidate->sessionId, $candidate->sourceVersion]
                        === [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion]) {
                    $decision = $candidate;
                    break;
                }
            }
            if (! $decision instanceof Decision) {
                return ['issue' => $this->issue('assumption_decision_missing', $role, $fact->id)];
            }
        }
        $evidencePayload = [];
        foreach ($fact->evidenceIds as $evidenceId) {
            $item = $evidence[$evidenceId] ?? null;
            if (! $item instanceof Evidence
                || [$item->organizationId, $item->projectId, $item->sessionId, $item->sourceVersion]
                    !== [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion]) {
                return ['issue' => $this->issue('evidence_missing_or_replaced', $role, $fact->id)];
            }
            $evidencePayload[] = [
                'id' => $item->id,
                'source_artifact_id' => $item->sourceArtifactId,
                'source_type' => $item->sourceType,
                'source_version' => $item->sourceVersion,
                'page' => $item->page,
                'region' => $item->region,
                'native_reference' => $item->nativeReference,
            ];
        }
        if ($evidencePayload === [] && ! $decision instanceof Decision) {
            return ['issue' => $this->issue('evidence_missing_or_replaced', $role, $fact->id)];
        }
        $normalized = $sourceValue->multipliedBy($factor);

        return [
            'role' => $role,
            'fact_id' => $fact->id,
            'fact_version' => $fact->version,
            'projection_version' => $fact->version,
            'status' => $fact->status,
            'current' => true,
            'source_value' => (string) $sourceValue,
            'source_unit' => $fact->unit,
            'value' => (string) $normalized,
            'unit' => $this->canonicalUnit($group),
            'compatibility_group' => $group,
            'organization_id' => $fact->organizationId,
            'project_id' => $fact->projectId,
            'session_id' => $fact->sessionId,
            'entity_id' => $fact->entityId,
            'source_version' => $fact->sourceVersion,
            'evidence_ids' => $fact->evidenceIds,
            'evidence' => $evidencePayload,
            'decision_id' => $decision?->id,
            'decision_version' => $decision?->version,
            'decision_actor_id' => $decision?->actorId,
            'decision_reason' => $decision?->reason,
        ];
    }

    private function validateGeometryScope(string $formula, Entity $target, array $resolved, array $entities): ?array
    {
        if ($formula === 'wall_net_area') {
            if (count($resolved['opening_widths']) !== count($resolved['opening_heights'])) {
                return $this->issue('opening_operand_mismatch', 'openings');
            }
            $geometry = [];
            foreach ($resolved['opening_widths'] as $index => $width) {
                $height = $resolved['opening_heights'][$index];
                if ($width['entity_id'] !== $height['entity_id']) {
                    return $this->issue('opening_operand_mismatch', 'openings');
                }
                $opening = $entities[$width['entity_id']] ?? null;
                if (! $opening instanceof Entity || ($opening->attributes['wall_id'] ?? null) !== $target->id) {
                    return $this->issue('entity_scope_mismatch', 'openings', $width['fact_id']);
                }
                $identity = $opening->attributes['geometry_identity'] ?? $opening->id;
                if (isset($geometry[$identity])) {
                    return $this->issue('duplicate_geometry', 'openings', $width['fact_id']);
                }
                $geometry[$identity] = true;
            }
        }
        if ($formula === 'sloped_roof_area') {
            $count = count($resolved['plan_areas']);
            if ($count !== count($resolved['slope_rises']) || $count !== count($resolved['slope_runs'])) {
                return $this->issue('roof_operand_mismatch', 'roof_facets');
            }
            for ($index = 0; $index < $count; $index++) {
                $entityId = $resolved['plan_areas'][$index]['entity_id'];
                $facet = $entities[$entityId] ?? null;
                if ($resolved['slope_rises'][$index]['entity_id'] !== $entityId
                    || $resolved['slope_runs'][$index]['entity_id'] !== $entityId
                    || ! $facet instanceof Entity || ($facet->attributes['roof_id'] ?? null) !== $target->id) {
                    return $this->issue('entity_scope_mismatch', 'roof_facets', $resolved['plan_areas'][$index]['fact_id']);
                }
            }
            $geometry = [];
            foreach ($resolved['roof_opening_areas'] ?? [] as $openingArea) {
                $opening = $entities[$openingArea['entity_id']] ?? null;
                if (! $opening instanceof Entity || ($opening->attributes['roof_id'] ?? null) !== $target->id) {
                    return $this->issue('entity_scope_mismatch', 'roof_openings', $openingArea['fact_id']);
                }
                $identity = $opening->attributes['geometry_identity'] ?? $opening->id;
                if (isset($geometry[$identity])) {
                    return $this->issue('duplicate_geometry', 'roof_openings', $openingArea['fact_id']);
                }
                $geometry[$identity] = true;
            }
        }

        return null;
    }

    private function calculate(string $formula, array $resolved): BigDecimal
    {
        return match ($formula) {
            'floor_area' => $this->decimal($resolved['length'][0])->multipliedBy($this->decimal($resolved['width'][0])),
            'earthwork_volume' => $this->decimal($resolved['area'][0])->multipliedBy($this->decimal($resolved['depth'][0])),
            'technology_work_package' => $this->decimal($resolved['base_quantity'][0])
                ->multipliedBy($this->decimal($resolved['coefficient'][0])),
            'wall_net_area' => $this->wallArea($resolved),
            'sloped_roof_area' => $this->roofArea($resolved),
        };
    }

    private function wallArea(array $resolved): BigDecimal
    {
        $area = $this->decimal($resolved['wall_length'][0])->multipliedBy($this->decimal($resolved['wall_height'][0]));
        foreach ($resolved['opening_widths'] as $index => $width) {
            $area = $area->minus($this->decimal($width)->multipliedBy($this->decimal($resolved['opening_heights'][$index])));
        }

        return $area;
    }

    private function roofArea(array $resolved): BigDecimal
    {
        $area = BigDecimal::zero();
        foreach ($resolved['plan_areas'] as $index => $planArea) {
            $rise = $this->decimal($resolved['slope_rises'][$index]);
            $run = $this->decimal($resolved['slope_runs'][$index]);
            $hypotenuse = $rise->multipliedBy($rise)->plus($run->multipliedBy($run))
                ->sqrt(12, RoundingMode::HalfUp);
            $factor = $hypotenuse->dividedBy($run, 12, RoundingMode::HalfUp);
            $area = $area->plus($this->decimal($planArea)->multipliedBy($factor));
        }
        foreach ($resolved['roof_opening_areas'] ?? [] as $openingArea) {
            $area = $area->minus($this->decimal($openingArea));
        }

        return $area;
    }

    private function outputUnit(string $formula, array $resolved): string
    {
        return match ($formula) {
            'earthwork_volume' => 'm3',
            'technology_work_package' => $resolved['base_quantity'][0]['unit'],
            default => 'm2',
        };
    }

    private function expectedGroup(string $formula, string $role): string
    {
        if ($role === 'coefficient') {
            return 'count';
        }
        if (in_array($role, ['area', 'base_quantity', 'plan_areas', 'roof_opening_areas'], true)) {
            return 'area';
        }

        return 'length';
    }

    private function canonicalUnit(string $group): string
    {
        return match ($group) {
            'length' => 'm', 'area' => 'm2', 'volume' => 'm3', 'count' => 'count',
        };
    }

    private function decimal(array $operand): BigDecimal
    {
        return BigDecimal::of($operand['value']);
    }

    private function roundingMode(string $mode): RoundingMode
    {
        return match ($mode) {
            'half_up' => RoundingMode::HalfUp,
            'half_even' => RoundingMode::HalfEven,
            'floor' => RoundingMode::Floor,
            'ceil' => RoundingMode::Ceiling,
            default => RoundingMode::Unnecessary,
        };
    }

    private function boundedLimit(mixed $value, int $default, int $minimum, int $maximum): int
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum ? $value : $default;
    }

    private function unresolved(string $code, string $operand, ?string $factId = null): QuantityReadiness
    {
        return $this->issues([$this->issue($code, $operand, $factId)]);
    }

    /** @param list<array{code: string, operand: string, fact_id: string|null}> $issues */
    private function issues(array $issues): QuantityReadiness
    {
        usort($issues, static fn (array $left, array $right): int => [$left['operand'], $left['fact_id'], $left['code']]
            <=> [$right['operand'], $right['fact_id'], $right['code']]);
        $unique = [];
        foreach ($issues as $issue) {
            $unique[implode('|', [$issue['code'], $issue['operand'], $issue['fact_id'] ?? ''])] = $issue;
        }
        $issues = array_values($unique);
        $questions = array_map(static fn (array $issue): array => [
            'code' => $issue['code'],
            'message_key' => 'estimate_generation.quantity.'.$issue['code'],
            'operand' => $issue['operand'],
        ], $issues);

        return new QuantityReadiness(null, $issues, $questions);
    }

    /** @return array{code: string, operand: string, fact_id: string|null} */
    private function issue(string $code, string $operand, ?string $factId = null): array
    {
        return ['code' => $code, 'operand' => $operand, 'fact_id' => $factId];
    }
}
