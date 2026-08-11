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

    public function analyze(ProjectModelSnapshot $snapshot, array $recommendations, array $decisions = []): ProjectCompletenessResult
    {
        $factsByType = [];
        $factsById = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->status === 'confirmed') {
                $factsByType[$fact->type][] = $fact;
                $factsById[$fact->id] = $fact;
            }
        }
        $decisionsById = [];
        foreach ($decisions as $decision) {
            if ($decision instanceof Decision) {
                $decisionsById[$decision->id] = $decision;
            }
        }
        $exclusions = [];
        foreach ($factsByType['completeness_exclusion'] ?? [] as $fact) {
            if ($fact->origin === 'user_assumption' && is_array($fact->value)
                && isset($fact->value['rule_id'], $fact->value['decision_id'], $fact->value['actor'], $fact->value['reason'])) {
                $decision = $decisionsById[(string) $fact->value['decision_id']] ?? null;
                if ($decision instanceof Decision && $decision->selectedFactId === $fact->id
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
            $evidence = [];
            foreach ($rule->applicabilityFactTypes as $type) {
                foreach ($factsByType[$type] ?? [] as $fact) {
                    if ($this->applicableValue($fact->value)) {
                        $evidence[$fact->id] = true;
                    }
                }
            }
            $applicable = $evidence !== [];
            $satisfaction = $factsByType[$rule->satisfactionFactType][0] ?? null;
            if ($satisfaction instanceof Fact) {
                $evidence[$satisfaction->id] = true;
            }
            $exclusion = $exclusions[$rule->id] ?? null;
            $status = ! $applicable ? 'not_applicable' : ($satisfaction instanceof Fact && $satisfaction->value === false ? 'proven_missing' : ($satisfaction instanceof Fact ? 'satisfied' : 'unknown'));
            if ($exclusion !== null && $applicable) {
                $status = 'excluded';
            }
            $classification = $applicable ? $rule->classification : 'not_applicable';
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
            if (in_array($status, ['unknown', 'proven_missing'], true) && $packageCount < $this->maxPackages) {
                $workPackage = $this->packages->build($rule, $factsByType);
                $packageCount++;
            } elseif (in_array($status, ['unknown', 'proven_missing'], true)) {
                $limitations[] = 'completeness_package_budget_reached';
            }
            $findings[] = new CompletenessFinding(
                $rule->id, $rule->version, $rule->contentHash, $classification, $status,
                $rule->severity, ($this->translate)($rule->impact), $status === 'proven_missing' ? 1.0 : 0.75,
                $evidenceIds, array_keys($relatedEntityIds), $rule->applicabilityFactTypes,
                $rule->exclusionPolicy, $exclusion, $workPackage,
            );
        }

        return new ProjectCompletenessResult($this->catalog->version, $this->catalog->contentHash, $findings, array_values(array_unique($limitations)));
    }

    private function applicableValue(mixed $value): bool
    {
        return ! in_array($value, [null, false, 0, '0', 'false'], true);
    }
}
