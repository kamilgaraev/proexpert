<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;

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
            ->filter(fn (array $entry): bool => !$this->hardGated($entry['candidate']) && $this->scopeCompatible($entry['candidate'], $workItem, $context))
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
        if (!$this->scopeCompatible($candidate, $workItem, $context)) {
            return -1000.0;
        }

        $score = 0.0;

        if (!$this->hardGated($candidate)) {
            $score += 100.0;
        }

        if ($this->unitCompatible($candidate, $workItem)) {
            $score += 40.0;
        }

        if ($this->scopeCompatible($candidate, $workItem, $context)) {
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
    private function scopeCompatible(array $candidate, array $workItem, array $context): bool
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog()))->classify($workItem, $context);
        $scope = $intent->scope;
        $system = (string) ($intent->system ?? '');
        $action = $intent->action;
        $section = is_array($candidate['section'] ?? null) ? $candidate['section'] : [];
        $sectionCode = (string) ($section['code'] ?? substr((string) ($candidate['code'] ?? ''), 0, 2));

        if ($this->hasForbiddenDomain($candidate, $workItem, $scope, $system, $action)) {
            return false;
        }

        if ($scope === '' || in_array($scope, ['general', 'general_work'], true)) {
            return true;
        }

        $allowedPrefixes = $this->allowedSectionPrefixes($scope, $system, $action);
        if ($allowedPrefixes !== [] && $sectionCode !== '') {
            return $this->startsWithAny($sectionCode, $allowedPrefixes);
        }

        return !$this->startsWithAny($sectionCode, $this->forbiddenSectionPrefixes($scope));
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $workItem
     */
    private function hasForbiddenDomain(array $candidate, array $workItem, string $scope, string $system, string $action): bool
    {
        $candidateText = $this->candidateText($candidate);
        $workText = mb_strtolower(trim(implode(' ', array_filter([
            (string) ($workItem['name'] ?? ''),
            (string) ($workItem['description'] ?? ''),
            (string) ($workItem['work_category'] ?? ''),
            (string) ($workItem['normative_search_text'] ?? ''),
        ]))));

        if ($this->containsAny($candidateText, ['кран портальн', 'портальный кран', 'кран козлов']) && !$this->containsAny($workText, ['кран', 'подъемн'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['железнодорож', 'земляное полотно']) && !$this->containsAny($workText, ['железнодорож', 'рельс', 'путь'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['бурени', 'скважин']) && !$this->containsAny($workText, ['бурени', 'скважин'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['взрыв', 'взрываем']) && !$this->containsAny($workText, ['взрыв', 'взрываем'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['шпунт']) && !$this->containsAny($workText, ['шпунт'])) {
            return true;
        }

        if (
            $this->containsAny($candidateText, ['водопроводн арматур', 'арматур водопровод'])
            && !in_array($system, ['water_supply', 'sewerage'], true)
            && $action !== 'pipe_layout'
        ) {
            return true;
        }

        if (
            $this->containsAny($candidateText, ['землян', 'разработк грунт', 'котлован', 'транше'])
            && !in_array($scope, ['foundation', 'site'], true)
            && !in_array($action, ['excavation', 'backfill'], true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateText(array $candidate): string
    {
        $section = is_array($candidate['section'] ?? null) ? $candidate['section'] : [];
        $collection = is_array($candidate['collection'] ?? null) ? $candidate['collection'] : [];

        return mb_strtolower(trim(implode(' ', array_filter([
            (string) ($candidate['code'] ?? ''),
            (string) ($candidate['name'] ?? ''),
            (string) ($section['name'] ?? ''),
            (string) ($collection['name'] ?? ''),
        ]))));
    }

    /**
     * @return array<int, string>
     */
    private function allowedSectionPrefixes(string $scope, string $system, string $action): array
    {
        if ($scope === 'engineering') {
            if ($system === 'electrical' || in_array($action, ['cable_installation', 'socket_installation'], true)) {
                return ['08'];
            }

            if ($system === 'ventilation' || $action === 'ventilation_installation') {
                return ['20'];
            }

            if ($system === 'heating' && $action === 'heating_equipment') {
                return ['18', '20'];
            }

            if (in_array($system, ['heating', 'water_supply', 'sewerage'], true) || $action === 'pipe_layout') {
                return ['16', '18'];
            }

            return ['08', '16', '18', '20'];
        }

        return match ($scope) {
            'roof' => ['10', '12', '26'],
            'walls' => $action === 'masonry' ? ['08'] : ['07', '08'],
            'slabs' => ['06', '07'],
            'facade' => ['15', '26'],
            'finishing' => match ($action) {
                'floor_covering', 'baseboard_installation' => ['11'],
                default => ['15'],
            },
            'openings' => ['10', '15'],
            'temporary' => ['08', '09'],
            'site' => ['01', '27'],
            'foundation' => in_array($action, ['excavation', 'backfill'], true)
                ? ['01']
                : (in_array($action, ['concreting', 'reinforcement', 'formwork'], true) ? ['01', '06'] : ['01', '06', '07', '08']),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function forbiddenSectionPrefixes(string $scope): array
    {
        return match ($scope) {
            'engineering', 'roof', 'walls', 'facade', 'finishing', 'openings' => ['01', '09', '27'],
            'temporary' => ['01', '27'],
            default => [],
        };
    }

    /**
     * @param array<int, string> $prefixes
     */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($value !== '' && str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
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

        if ($this->scopeCompatible($candidate, $workItem, $context)) {
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
