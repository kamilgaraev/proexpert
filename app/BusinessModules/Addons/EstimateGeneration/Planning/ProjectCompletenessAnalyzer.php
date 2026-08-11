<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use Closure;
use InvalidArgumentException;

final readonly class ProjectCompletenessAnalyzer
{
    private Closure $translate;

    public function __construct(
        private CompletenessRuleCatalog $catalog,
        private TechnologyWorkPackageBuilder $packages,
        private int $maxFindings,
        private int $maxPackages,
        private int $maxEvidence,
        private int $maxRules = 100,
        private int $maxEvidenceBytes = 262144,
        ?Closure $translate = null,
    ) {
        if ($maxFindings < 1 || $maxPackages < 1 || $maxEvidence < 1 || $maxRules < 1 || $maxEvidenceBytes < 1) {
            throw new InvalidArgumentException('Completeness limits are invalid.');
        }
        $this->translate = $translate ?? static fn (string $key): string => $key;
    }

    public function analyze(
        ProjectModelSnapshot $snapshot,
        array $recommendations,
        array $decisions = [],
        array $projection = [],
    ): ProjectCompletenessResult {
        $allFactsByType = [];
        $factsByType = [];
        $factsById = [];
        foreach ($snapshot->facts as $fact) {
            $allFactsByType[$fact->type][] = $fact;
            $factsById[$fact->id] = $fact;
            if ($fact->status === 'confirmed') {
                $factsByType[$fact->type][] = $fact;
            }
        }
        $decisionsById = [];
        foreach ($decisions as $decision) {
            if ($decision instanceof Decision) {
                $decisionsById[$decision->id] = $decision;
            }
        }
        $exclusions = [];
        $exclusionFacts = [];
        foreach ($factsByType as $type => $typedFacts) {
            if ($type === 'completeness_exclusion' || str_starts_with($type, 'completeness_exclusion.')) {
                $exclusionFacts = [...$exclusionFacts, ...$typedFacts];
            }
        }
        foreach ($exclusionFacts as $fact) {
            if ($fact->origin === 'user_assumption' && is_array($fact->value)
                && isset($fact->value['rule_id'], $fact->value['decision_id'], $fact->value['actor'], $fact->value['reason'])) {
                $decision = $decisionsById[(string) $fact->value['decision_id']] ?? null;
                if ($decision instanceof Decision && $decision->selectedFactId === $fact->id
                    && [$decision->organizationId, $decision->projectId, $decision->sessionId, $decision->sourceVersion]
                        === [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion]
                    && $decision->actorId === $fact->value['actor'] && $decision->reason === $fact->value['reason']) {
                    $exclusions[(string) $fact->value['rule_id']] = $fact->value;
                }
            }
        }
        $findings = [];
        $evidenceCount = 0;
        $evidenceBytes = 0;
        $packageCount = 0;
        $rules = $this->catalog->rules();
        $limitations = count($rules) > $this->maxRules ? ['completeness_rule_budget_reached'] : [];
        foreach (array_slice($rules, 0, $this->maxRules) as $rule) {
            if (count($findings) >= $this->maxFindings) {
                $limitations[] = 'completeness_finding_budget_reached';
                break;
            }
            [$applicabilityStatus, $applicabilityEvidence, $applicableEntityIds] = $this->evaluateConditions(
                $rule->conditions,
                $factsByType,
            );
            $evidence = array_fill_keys($applicabilityEvidence, true);
            $applicable = $applicabilityStatus === 'applicable';
            $targetFacts = $this->targetFacts(
                $allFactsByType[$rule->satisfactionFactType] ?? [],
                $applicableEntityIds,
            );
            $confirmedTargetFacts = array_values(array_filter(
                $targetFacts,
                static fn (Fact $fact): bool => $fact->status === 'confirmed',
            ));
            $satisfaction = $confirmedTargetFacts[0] ?? null;
            if ($satisfaction instanceof Fact) {
                $evidence[$satisfaction->id] = true;
            }
            $findingKey = hash('sha256', json_encode([
                $rule->id,
                $rule->version,
                $rule->contentHash,
                $this->factStates($this->facts($applicabilityEvidence, $factsById)),
                $this->factStates($targetFacts),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            $exclusion = $this->validExclusion($exclusions[$rule->id] ?? null, $rule, $findingKey, $projection);
            $status = match ($applicabilityStatus) {
                'not_applicable' => 'not_applicable',
                'unknown' => 'unknown',
                default => $this->satisfactionStatus($satisfaction, $rule->satisfaction, $rule->classification),
            };
            $technologyReady = $this->technologyReady($rule, $snapshot, $recommendations);
            if ($applicable && $rule->technologyRequirement !== null && ! $technologyReady) {
                $status = 'unresolved';
            }
            if ($exclusion !== null && $applicable) {
                $status = 'excluded';
            }
            $classification = ! $applicable
                ? ($applicabilityStatus === 'unknown' ? 'technology_conditional' : 'not_applicable')
                : ($status === 'unresolved' ? 'technology_conditional' : $rule->classification);
            $evidenceIds = [];
            foreach (array_keys($evidence) as $evidenceId) {
                $bytes = strlen($evidenceId);
                if ($evidenceCount >= $this->maxEvidence || $evidenceBytes + $bytes > $this->maxEvidenceBytes) {
                    $limitations[] = 'completeness_evidence_budget_reached';
                    break;
                }
                $evidenceIds[] = $evidenceId;
                $evidenceCount++;
                $evidenceBytes += $bytes;
            }
            $relatedEntityIds = [];
            foreach ($evidenceIds as $factId) {
                $fact = $factsById[$factId] ?? null;
                if ($fact instanceof Fact) {
                    $relatedEntityIds[$fact->entityId] = true;
                }
            }
            if ($applicable && $evidenceIds === []) {
                break;
            }
            $workPackage = null;
            if (in_array($status, ['unknown', 'proven_missing'], true) && $applicable && $packageCount < $this->maxPackages) {
                $workPackage = $this->packages->build($rule, $factsByType);
                $packageCount++;
            } elseif (in_array($status, ['unknown', 'proven_missing'], true)) {
                $limitations[] = 'completeness_package_budget_reached';
            }
            $findings[] = new CompletenessFinding(
                $rule->id, $rule->version, $rule->contentHash, $findingKey, 1, $classification, $status,
                $rule->severity, ($this->translate)($rule->impact), $status === 'proven_missing' ? 1.0 : 0.75,
                $evidenceIds, array_keys($relatedEntityIds), $rule->applicabilityFactTypes,
                ['status' => $applicabilityStatus, 'conditions' => $rule->conditions, 'evidence_fact_ids' => $applicabilityEvidence],
                $rule->exclusionPolicy, $exclusion, $workPackage,
            );
        }

        return new ProjectCompletenessResult($this->catalog->version, $this->catalog->contentHash, $findings, array_values(array_unique($limitations)));
    }

    private function evaluateConditions(array $conditions, array $factsByType): array
    {
        $evidence = [];
        $matchedEntities = [];
        $unknown = false;
        foreach ($conditions as $condition) {
            $facts = $factsByType[(string) ($condition['fact_type'] ?? '')] ?? [];
            if ($facts === []) {
                sort($evidence, SORT_STRING);

                return ['unknown', array_values(array_unique($evidence)), array_keys($matchedEntities)];
            }
            $matched = false;
            foreach ($facts as $fact) {
                $evidence[] = $fact->id;
                if ($fact->value === null) {
                    $unknown = true;

                    continue;
                }
                if (isset($condition['unit']) && $fact->unit !== $condition['unit']) {
                    continue;
                }
                $operator = (string) ($condition['operator'] ?? '');
                $factMatches = match ($operator) {
                    'present' => true,
                    '=' => $fact->value === ($condition['value'] ?? null),
                    '!=' => $fact->value !== ($condition['value'] ?? null),
                    'in' => in_array($fact->value, $condition['values'] ?? [], true),
                    '>', '>=', '<', '<=' => $this->compare($fact->value, $condition['value'] ?? null, $operator),
                    default => throw new InvalidArgumentException('Completeness condition operator is invalid.'),
                };
                if ($factMatches) {
                    $matched = true;
                    $matchedEntities[$fact->entityId] = true;
                }
            }
            if (! $matched && ! $unknown) {
                sort($evidence, SORT_STRING);

                return ['not_applicable', array_values(array_unique($evidence)), array_keys($matchedEntities)];
            }
            if (! $matched) {
                $unknown = true;
            }
        }

        sort($evidence, SORT_STRING);
        $entities = array_keys($matchedEntities);
        sort($entities, SORT_STRING);

        return [$unknown ? 'unknown' : 'applicable', array_values(array_unique($evidence)), $entities];
    }

    /** @param list<Fact> $facts */
    private function targetFacts(array $facts, array $entityIds): array
    {
        $entities = array_fill_keys($entityIds, true);
        $target = array_values(array_filter(
            $facts,
            static fn (Fact $fact): bool => isset($entities[$fact->entityId]),
        ));
        usort($target, fn (Fact $left, Fact $right): int => $this->factStateJson($left) <=> $this->factStateJson($right));

        return $target;
    }

    /** @return list<Fact> */
    private function facts(array $ids, array $factsById): array
    {
        $facts = [];
        foreach ($ids as $id) {
            $fact = $factsById[$id] ?? null;
            if ($fact instanceof Fact) {
                $facts[$fact->id] = $fact;
            }
        }

        return array_values($facts);
    }

    /** @param list<Fact> $facts */
    private function factStates(array $facts): array
    {
        $states = array_map(fn (Fact $fact): array => $this->factState($fact), $facts);
        usort($states, static fn (array $left, array $right): int => json_encode(
            $left,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ) <=> json_encode(
            $right,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));

        return $states;
    }

    private function factState(Fact $fact): array
    {
        $evidenceIds = $fact->evidenceIds;
        sort($evidenceIds, SORT_STRING);

        return [
            'id' => $fact->id,
            'organization_id' => $fact->organizationId,
            'project_id' => $fact->projectId,
            'session_id' => $fact->sessionId,
            'source_version' => $fact->sourceVersion,
            'entity_id' => $fact->entityId,
            'type' => $fact->type,
            'value' => $this->canonicalValue($fact->value),
            'unit' => $fact->unit,
            'confidence' => $fact->confidence,
            'origin' => $fact->origin,
            'status' => $fact->status,
            'evidence_ids' => $evidenceIds,
            'version' => $fact->version,
            'supersedes_fact_id' => $fact->supersedesFactId,
        ];
    }

    private function factStateJson(Fact $fact): string
    {
        return json_encode(
            $this->factState($fact),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }

    private function validExclusion(?array $exclusion, CompletenessRule $rule, string $findingKey, array $projection): ?array
    {
        $policy = $rule->exclusionPolicy;
        if ($exclusion === null || ($policy['allowed'] ?? false) !== true) {
            return null;
        }
        $expected = [
            'rule_id' => $rule->id,
            'rule_version' => $rule->version,
            'rule_hash' => $rule->contentHash,
            'finding_key' => $findingKey,
            'finding_version' => 1,
            'policy_id' => $policy['id'] ?? null,
            'policy_version' => $policy['version'] ?? null,
            'source_version' => $projection['source_version'] ?? null,
            'catalog_version' => $projection['catalog_version'] ?? null,
            'catalog_hash' => $projection['catalog_hash'] ?? null,
            'rule_catalog_version' => $projection['rule_catalog_version'] ?? null,
            'rule_catalog_hash' => $projection['rule_catalog_hash'] ?? null,
        ];
        foreach ($expected as $key => $value) {
            if ($value === null || ($exclusion[$key] ?? null) !== $value) {
                return null;
            }
        }

        return $exclusion;
    }

    private function compare(mixed $actual, mixed $expected, string $operator): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }
        $comparison = (float) $actual <=> (float) $expected;

        return match ($operator) {
            '>' => $comparison > 0,
            '>=' => $comparison >= 0,
            '<' => $comparison < 0,
            '<=' => $comparison <= 0,
        };
    }

    private function satisfactionStatus(?Fact $fact, array $condition, string $classification): string
    {
        if (! $fact instanceof Fact || $fact->value === null) {
            return 'unknown';
        }
        if ($classification === 'document_missing' && $fact->origin === 'user_assumption') {
            return 'unknown';
        }
        if (($condition['false_means_missing'] ?? false) === true && $fact->value === false) {
            return 'proven_missing';
        }

        return 'satisfied';
    }

    private function technologyReady(CompletenessRule $rule, ProjectModelSnapshot $snapshot, array $recommendations): bool
    {
        if ($rule->technologyRequirement === null) {
            return true;
        }
        $kind = (string) ($rule->technologyRequirement['decision_kind'] ?? '');
        foreach ($snapshot->facts as $fact) {
            if ($fact->status === 'confirmed' && $fact->origin === 'user_assumption' && is_array($fact->value)
                && ($fact->value['kind'] ?? null) === 'catalog_system'
                && str_starts_with((string) ($fact->value['decision_key'] ?? $kind), $kind)) {
                return true;
            }
        }
        if (($rule->technologyRequirement['allow_recommended_applicable'] ?? false) !== true) {
            return false;
        }
        foreach ($recommendations as $recommendation) {
            if ($recommendation instanceof TechnologyRecommendation
                && str_starts_with($recommendation->decisionKey, $kind.'.')
                && $recommendation->recommendedOption()?->applicabilityStatus === 'applicable') {
                return true;
            }
        }

        return false;
    }
}
