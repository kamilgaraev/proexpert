<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;

final class RuleBasedNormativeCandidateReranker implements NormativeCandidateRerankerInterface
{
    private const HARD_WARNINGS = [
        'unit_mismatch',
        'scope_mismatch',
        'norm_without_resources',
        'norm_without_resource_prices',
        'norm_without_prices',
    ];

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $candidates
     */
    public function rerank(array $workItem, array $context, array $candidates): NormativeRerankResultData
    {
        $ranked = collect($candidates)
            ->map(fn (array $candidate): array => [
                'candidate' => $candidate,
                'score' => $this->score($candidate, $workItem, $context),
            ])
            ->filter(fn (array $entry): bool => !$this->hardGated($entry['candidate']))
            ->sortByDesc('score')
            ->values();

        if ($ranked->isEmpty()) {
            return new NormativeRerankResultData(
                selectedCandidateKey: null,
                confidence: 0.0,
                reason: 'no_safe_candidate',
                evidenceKeys: [],
                warnings: ['all_candidates_hard_gated'],
                provider: 'rule_based',
            );
        }

        $selected = $ranked->first();
        $candidate = $selected['candidate'];
        $evidenceKeys = $this->evidenceKeys($candidate, $workItem, $context);

        return new NormativeRerankResultData(
            selectedCandidateKey: (string) ($candidate['key'] ?? ''),
            confidence: $this->confidence((float) $selected['score']),
            reason: 'highest_safe_score',
            evidenceKeys: $evidenceKeys,
            warnings: [],
            provider: 'rule_based',
        );
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     */
    public function score(array $candidate, array $workItem, array $context): float
    {
        $score = 0.0;

        if (!$this->hardGated($candidate)) {
            $score += 100.0;
        }

        if ($this->unitCompatible($candidate, $workItem)) {
            $score += 40.0;
        }

        if ($this->scopeCompatible($candidate, $context)) {
            $score += 20.0;
        }

        $score += (float) ($candidate['learning_score'] ?? 0) * 1.4;
        $score += (float) ($candidate['score'] ?? 0) * 0.35;
        $score += (float) ($candidate['confidence'] ?? 0) * 16.0;
        $score += min($this->resourcesCount($candidate['resources'] ?? []), 8) * 1.5;
        $score += min($this->pricedResourcesCount($candidate['resources'] ?? []), 6) * 2.0;

        if ((int) ($candidate['learning_negative_count'] ?? 0) > 0) {
            $score -= min((int) $candidate['learning_negative_count'], 4) * 8.0;
        }

        return round($score, 4);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    public function hardGated(array $candidate): bool
    {
        $warnings = array_map('strval', is_array($candidate['warnings'] ?? null) ? $candidate['warnings'] : []);

        return array_intersect($warnings, self::HARD_WARNINGS) !== [];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $workItem
     */
    private function unitCompatible(array $candidate, array $workItem): bool
    {
        $candidateUnit = (string) ($candidate['unit'] ?? '');
        $workUnit = (string) ($workItem['unit'] ?? '');

        return $candidateUnit !== '' && $workUnit !== '' && NormativeUnitNormalizer::compatible($candidateUnit, $workUnit);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $context
     */
    private function scopeCompatible(array $candidate, array $context): bool
    {
        $scope = (string) ($context['scope_type'] ?? '');
        $section = is_array($candidate['section'] ?? null) ? $candidate['section'] : [];
        $sectionCode = (string) ($section['code'] ?? substr((string) ($candidate['code'] ?? ''), 0, 2));

        if ($scope === '') {
            return true;
        }

        if (in_array($scope, ['engineering', 'roof', 'walls', 'facade', 'finishing'], true) && str_starts_with($sectionCode, '01')) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function evidenceKeys(array $candidate, array $workItem, array $context): array
    {
        $keys = [];

        if ($this->unitCompatible($candidate, $workItem)) {
            $keys[] = 'unit_dimension';
        }

        if ($this->scopeCompatible($candidate, $context)) {
            $keys[] = 'scope';
        }

        if ((float) ($candidate['learning_score'] ?? 0) > 0) {
            $keys[] = 'learning_score';
        }

        if ((float) ($candidate['score'] ?? 0) > 0) {
            $keys[] = 'lexical_score';
        }

        if ($this->resourcesCount($candidate['resources'] ?? []) > 0) {
            $keys[] = 'resources';
        }

        if ($this->pricedResourcesCount($candidate['resources'] ?? []) > 0) {
            $keys[] = 'prices';
        }

        return array_values(array_unique($keys));
    }

    private function confidence(float $score): float
    {
        return round(min(0.95, max(0.35, $score / 180)), 4);
    }

    /**
     * @param mixed $resources
     */
    private function resourcesCount(mixed $resources): int
    {
        if (!is_array($resources)) {
            return 0;
        }

        return count($resources['materials'] ?? [])
            + count($resources['machinery'] ?? [])
            + count($resources['labor'] ?? [])
            + count($resources['other'] ?? []);
    }

    /**
     * @param mixed $resources
     */
    private function pricedResourcesCount(mixed $resources): int
    {
        if (!is_array($resources)) {
            return 0;
        }

        $count = 0;

        foreach ($resources as $group) {
            foreach (is_array($group) ? $group : [] as $resource) {
                if (($resource['price_source'] ?? null) !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
