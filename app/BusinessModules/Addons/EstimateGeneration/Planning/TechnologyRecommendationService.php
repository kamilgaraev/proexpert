<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use Closure;
use InvalidArgumentException;

final readonly class TechnologyRecommendationService
{
    private Closure $translator;

    public function __construct(private TechnologySystemCatalog $catalog, ?Closure $translator = null)
    {
        $this->translator = $translator ?? static fn (string $key): string => trans_message($key);
    }

    public function recommend(
        ProjectModelSnapshot $model,
        Fact $unresolvedDecision,
        OrganizationPreferenceContext $preferences,
    ): TechnologyRecommendation {
        if ($unresolvedDecision->organizationId !== $preferences->organizationId
            || $unresolvedDecision->origin !== 'unresolved' || $unresolvedDecision->status !== 'unresolved'
            || ! in_array($unresolvedDecision->type, ['material', 'material_name', 'roof_covering_system'], true)) {
            throw new InvalidArgumentException('Technology recommendation target is invalid.');
        }
        $facts = [];
        foreach ($model->facts as $fact) {
            if ($fact->organizationId !== $unresolvedDecision->organizationId
                || $fact->projectId !== $unresolvedDecision->projectId
                || $fact->sessionId !== $unresolvedDecision->sessionId
                || $fact->sourceVersion !== $unresolvedDecision->sourceVersion) {
                throw new InvalidArgumentException('Technology recommendation contains a cross-scope fact.');
            }
            if ($fact->status === 'confirmed') {
                $facts[$fact->type][] = $fact->value;
            }
        }
        $ranked = [];
        foreach ($this->catalog->systems as $system) {
            [$score, $contributions] = $this->score($system, $facts, $preferences);
            $ranked[] = ['system' => $system, 'score' => $score, 'contributions' => $contributions];
        }
        usort($ranked, static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: ($left['system']->id <=> $right['system']->id));
        $commonRequiredFacts = array_values(array_intersect(...array_map(
            static fn (TechnologySystem $system): array => $system->requiredFacts,
            $this->catalog->systems,
        )));
        $requiredFacts = array_values(array_unique([
            ...$this->catalog->requiredFacts,
            ...$commonRequiredFacts,
        ]));
        $missingFacts = array_values(array_filter(
            $requiredFacts,
            static fn (string $type): bool => ! isset($facts[$type]),
        ));
        sort($missingFacts, SORT_STRING);
        $options = [];
        foreach ($ranked as $index => $item) {
            $options[] = new TechnologySystemOption(
                system: $item['system'],
                score: $item['score'],
                scoreContributions: $item['contributions'],
                recommended: $index === 0,
                label: ($this->translator)($item['system']->nameKey),
                explanation: ($this->translator)('estimate_generation.planning.technology.explanation.'.$item['system']->id),
            );
        }

        return new TechnologyRecommendation(
            decisionKey: 'roof_covering_system',
            targetFactId: $unresolvedDecision->id,
            organizationId: $unresolvedDecision->organizationId,
            projectId: $unresolvedDecision->projectId,
            sessionId: $unresolvedDecision->sessionId,
            sourceVersion: $unresolvedDecision->sourceVersion,
            catalogVersion: $this->catalog->version,
            catalogHash: $this->catalog->contentHash,
            options: $options,
            responseOptions: [
                ['value' => 'other', 'label' => ($this->translator)('estimate_generation.planning.technology.other')],
                ['value' => 'leave_unresolved', 'label' => ($this->translator)('estimate_generation.planning.technology.leave_unresolved')],
            ],
            question: ($this->translator)('estimate_generation.planning.technology.roof_question'),
            conditional: $missingFacts !== [],
            missingFacts: $missingFacts,
        );
    }

    private function score(TechnologySystem $system, array $facts, OrganizationPreferenceContext $preferences): array
    {
        $score = 0;
        $contributions = [];
        foreach ($system->scoreRules as $rule) {
            if (! is_array($rule) || ! is_string($rule['fact_type'] ?? null)
                || ! is_int($rule['score'] ?? null)
                || ! is_string($rule['reason'] ?? null)) {
                throw new InvalidArgumentException('Technology system score rule is invalid.');
            }
            $values = $facts[$rule['fact_type']] ?? [];
            $matched = is_array($rule['values'] ?? null)
                ? array_intersect($values, $rule['values']) !== []
                : $this->matchesRange($values, $rule);
            if ($matched) {
                $score += $rule['score'];
                $contributions[] = ['reason' => $rule['reason'], 'score' => $rule['score'], 'source' => 'project_fact'];
            }
        }
        $preference = $preferences->tieBreaker($system->id);
        if ($preference !== 0) {
            $score += $preference;
            $contributions[] = ['reason' => 'organization_preference', 'score' => $preference, 'source' => 'tenant_tie_breaker'];
        }
        if ($contributions === []) {
            $contributions[] = ['reason' => 'catalog_default', 'score' => 0, 'source' => 'catalog'];
        }

        return [$score, $contributions];
    }

    private function matchesRange(array $values, array $rule): bool
    {
        $minimum = $rule['min'] ?? null;
        $maximum = $rule['max'] ?? null;
        if (($minimum !== null && ! is_int($minimum) && ! is_float($minimum))
            || ($maximum !== null && ! is_int($maximum) && ! is_float($maximum))
            || ($minimum === null && $maximum === null)) {
            throw new InvalidArgumentException('Technology system numeric score rule is invalid.');
        }
        foreach ($values as $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $number = (float) $value;
            if (($minimum === null || $number >= $minimum) && ($maximum === null || $number <= $maximum)) {
                return true;
            }
        }

        return false;
    }
}
