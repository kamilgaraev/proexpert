<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\NormativeMatchDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeCandidateDecisionContextData;

class NormativeMatchDecisionService
{
    private const ACCEPT_CONFIDENCE_THRESHOLD = 0.72;

    private const REVIEW_CONFIDENCE_THRESHOLD = 0.55;

    private const CANDIDATE_CONFIDENCE_THRESHOLD = 0.35;

    public function __construct(
        private readonly ?WorkIntentClassifier $workIntentClassifier = null,
        private readonly ?NormativeSearchProfileCatalog $searchProfileCatalog = null,
        private readonly ?NormativeSemanticCompatibilityService $semanticCompatibilityService = null,
    ) {}

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $workItem
     */
    public function decide(array $candidate, array $workItem): NormativeMatchDecisionData
    {
        $confidence = (float) ($candidate['confidence'] ?? 0);
        $warnings = [];
        $reasons = [];
        $unitCompatible = $this->unitCompatible((string) ($candidate['unit'] ?? ''), (string) ($workItem['unit'] ?? ''));
        $scopeCompatible = $this->scopeCompatible($candidate, $workItem);
        $semanticCompatible = $this->semanticCompatible($candidate, $workItem);
        $resourceCount = $this->resourcesCount($candidate['resources'] ?? []);
        $pricedCount = $this->pricedResourcesCount($candidate['resources'] ?? []);

        if (! $unitCompatible) {
            $warnings[] = 'unit_mismatch';
        } else {
            $reasons[] = 'unit_compatible';
        }

        if (! $scopeCompatible) {
            $warnings[] = 'scope_mismatch';
        } else {
            $reasons[] = 'scope_compatible';
        }

        if (! $semanticCompatible) {
            $warnings[] = 'semantic_mismatch';
        } else {
            $reasons[] = 'semantic_compatible';
        }

        if ($confidence < self::ACCEPT_CONFIDENCE_THRESHOLD) {
            $warnings[] = 'low_confidence';
        } else {
            $reasons[] = 'confidence_threshold_passed';
        }

        if ($resourceCount === 0) {
            $warnings[] = 'norm_without_resources';
        } else {
            $reasons[] = 'resources_present';
        }

        if ($pricedCount === 0) {
            $warnings[] = 'norm_without_prices';
        } elseif ($pricedCount < $resourceCount) {
            $warnings[] = 'norm_with_unpriced_resources';
        } else {
            $reasons[] = 'prices_present';
        }

        $context = new NormativeCandidateDecisionContextData(
            unitCompatible: $unitCompatible,
            scopeCompatible: $scopeCompatible,
            resourceCount: $resourceCount,
            pricedResourceCount: $pricedCount,
            hardWarnings: array_values(array_intersect($warnings, [
                'unit_mismatch',
                'scope_mismatch',
                'semantic_mismatch',
                'norm_without_resources',
                'norm_without_prices',
                'norm_with_unpriced_resources',
            ])),
            reviewWarnings: array_values(array_intersect($warnings, [
                'low_confidence',
            ])),
        );

        if ($context->hardWarnings !== []) {
            $status = $confidence >= self::CANDIDATE_CONFIDENCE_THRESHOLD ? 'candidate' : 'rejected';

            return new NormativeMatchDecisionData($status, false, $confidence, $reasons, $warnings, $candidate);
        }

        if ($confidence >= self::ACCEPT_CONFIDENCE_THRESHOLD) {
            return new NormativeMatchDecisionData('accepted', true, $confidence, $reasons, $warnings, $candidate);
        }

        if ($confidence >= self::REVIEW_CONFIDENCE_THRESHOLD) {
            $warnings[] = 'requires_normative_review';
            $warnings[] = 'safe_normative_analog';
            $reasons[] = 'safe_analog_pricing_allowed';

            return new NormativeMatchDecisionData('review_priced', true, $confidence, $reasons, array_values(array_unique($warnings)), $candidate);
        }

        $status = $confidence >= self::CANDIDATE_CONFIDENCE_THRESHOLD ? 'candidate' : 'rejected';

        return new NormativeMatchDecisionData($status, false, $confidence, $reasons, $warnings, $candidate);
    }

    private function unitCompatible(string $candidateUnit, string $workUnit): bool
    {
        if ($candidateUnit === '' || $workUnit === '') {
            return false;
        }

        return NormativeUnitNormalizer::compatible($candidateUnit, $workUnit);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $workItem
     */
    private function scopeCompatible(array $candidate, array $workItem): bool
    {
        $intent = $this->workIntent($workItem);
        $scope = (string) ($intent['scope'] ?? '');

        if ($this->hasForbiddenDomain($candidate, $workItem, $intent)) {
            return false;
        }

        $sectionCode = $this->candidateSectionCode($candidate);

        if ($scope === '' || in_array($scope, ['general', 'general_work'], true)) {
            return true;
        }

        $intentForbiddenPrefixes = $this->stringList($intent['forbidden_section_prefixes'] ?? []);
        if ($intentForbiddenPrefixes !== [] && $this->startsWithAny($sectionCode, $intentForbiddenPrefixes)) {
            return false;
        }

        $intentPreferredPrefixes = $this->stringList($intent['preferred_section_prefixes'] ?? []);
        if ($intentPreferredPrefixes !== [] && $sectionCode !== '') {
            return $this->startsWithAny($sectionCode, $intentPreferredPrefixes);
        }

        $allowedPrefixes = $this->allowedSectionPrefixes($scope, (string) ($intent['system'] ?? ''), (string) ($intent['action'] ?? ''));
        if ($allowedPrefixes !== [] && $sectionCode !== '') {
            return $this->startsWithAny($sectionCode, $allowedPrefixes);
        }

        $forbiddenPrefixes = $this->forbiddenSectionPrefixes($scope);
        if ($forbiddenPrefixes !== [] && $this->startsWithAny($sectionCode, $forbiddenPrefixes)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $workItem
     */
    private function semanticCompatible(array $candidate, array $workItem): bool
    {
        $intent = $this->workIntent($workItem);
        $profileCatalog = $this->searchProfileCatalog ?? new NormativeSearchProfileCatalog;
        $profile = $profileCatalog->forIntent(
            (string) ($intent['scope'] ?? ''),
            (string) ($intent['action'] ?? ''),
            isset($intent['system']) ? (string) $intent['system'] : null,
        );
        $service = $this->semanticCompatibilityService ?? new NormativeSemanticCompatibilityService;
        $candidateText = $this->candidateSemanticText($candidate);

        if ($candidateText === '') {
            return true;
        }

        return $service->isCompatible(
            $candidateText,
            $this->workText($workItem),
            [...$intent, 'candidate_title' => (string) ($candidate['name'] ?? '')],
            $profile->forbiddenDomainTerms,
        );
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function workIntent(array $workItem): array
    {
        if (is_array($workItem['work_intent'] ?? null)) {
            return $workItem['work_intent'];
        }

        $classifier = $this->workIntentClassifier ?? new WorkIntentClassifier(new NormativeScopeRuleCatalog);
        $intent = $classifier->classify($workItem);

        return [
            'scope' => $intent->scope,
            'action' => $intent->action,
            'object' => $intent->object,
            'material' => $intent->material,
            'system' => $intent->system,
            'preferred_section_prefixes' => $intent->preferredSectionPrefixes,
            'forbidden_section_prefixes' => $intent->forbiddenSectionPrefixes,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $intent
     */
    private function hasForbiddenDomain(array $candidate, array $workItem, array $intent): bool
    {
        $candidateText = $this->candidateText($candidate);
        $workText = $this->workText($workItem);
        $scope = (string) ($intent['scope'] ?? '');
        $system = (string) ($intent['system'] ?? '');
        $action = (string) ($intent['action'] ?? '');

        if ($this->containsAny($candidateText, ['кран портальн', 'портальный кран', 'кран козлов']) && ! $this->containsAny($workText, ['кран', 'подъемн'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['железнодорож', 'земляное полотно']) && ! $this->containsAny($workText, ['железнодорож', 'рельс', 'путь'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['бурени', 'скважин']) && ! $this->containsAny($workText, ['бурени', 'скважин'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['взрыв', 'взрываем']) && ! $this->containsAny($workText, ['взрыв', 'взрываем'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['шпунт']) && ! $this->containsAny($workText, ['шпунт'])) {
            return true;
        }

        if (
            $this->containsAny($candidateText, ['водопроводн арматур', 'арматур водопровод'])
            && ! in_array($system, ['water_supply', 'sewerage'], true)
            && $action !== 'pipe_layout'
        ) {
            return true;
        }

        if (
            $this->containsAny($candidateText, ['землян', 'разработк грунт', 'котлован', 'транше'])
            && ! in_array($scope, ['foundation', 'site'], true)
            && ! in_array($action, ['excavation', 'backfill', 'soil_haulage'], true)
        ) {
            return true;
        }

        return false;
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
            'foundation' => in_array($action, ['excavation', 'backfill', 'soil_haulage'], true)
                ? ['01']
                : (match ($action) {
                    'concreting', 'reinforcement', 'formwork' => ['01', '06'],
                    'waterproofing' => ['08', '12'],
                    default => ['01', '06'],
                }),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function forbiddenSectionPrefixes(string $scope): array
    {
        return match ($scope) {
            'engineering', 'roof', 'walls', 'facade', 'finishing', 'openings' => ['01', '03', '05', '09', '27', '28'],
            'temporary' => ['01', '27', '28'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateSectionCode(array $candidate): string
    {
        $section = is_array($candidate['section'] ?? null) ? $candidate['section'] : [];
        $sectionCode = (string) ($section['code'] ?? $candidate['section_code'] ?? '');

        if ($sectionCode !== '') {
            return $sectionCode;
        }

        return substr((string) ($candidate['code'] ?? ''), 0, 2);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateText(array $candidate): string
    {
        $section = is_array($candidate['section'] ?? null) ? $candidate['section'] : [];
        $collection = is_array($candidate['collection'] ?? null) ? $candidate['collection'] : [];

        return mb_strtolower(trim(implode(' ', array_filter([
            (string) ($candidate['code'] ?? ''),
            (string) ($candidate['name'] ?? ''),
            (string) ($candidate['section_name'] ?? ''),
            (string) ($section['name'] ?? ''),
            (string) ($collection['name'] ?? ''),
        ]))));
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateSemanticText(array $candidate): string
    {
        $composition = is_array($candidate['work_composition'] ?? null)
            ? implode(' ', $candidate['work_composition'])
            : '';

        return mb_strtolower(trim(implode(' ', array_filter([
            (string) ($candidate['name'] ?? ''),
            $composition,
        ]))));
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function workText(array $workItem): string
    {
        return mb_strtolower(trim(implode(' ', array_filter([
            (string) ($workItem['name'] ?? ''),
            (string) ($workItem['description'] ?? ''),
            (string) ($workItem['work_category'] ?? ''),
            (string) ($workItem['normative_search_text'] ?? ''),
        ]))));
    }

    /**
     * @param  array<int, string>  $prefixes
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
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * @param  array<int, string>  $needles
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
     * @param  array<string, mixed>  $resources
     */
    private function resourcesCount(array $resources): int
    {
        return count($resources['materials'] ?? [])
            + count($resources['machinery'] ?? [])
            + count($resources['labor'] ?? [])
            + count($resources['other'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function pricedResourcesCount(array $resources): int
    {
        $count = 0;

        foreach ($resources as $group) {
            foreach ($group as $resource) {
                if (is_array($resource) && $this->resourceHasPositivePrice($resource)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resourceHasPositivePrice(array $resource): bool
    {
        return ($resource['price_source'] ?? null) !== null && $this->resourceTotalPrice($resource) > 0;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resourceTotalPrice(array $resource): float
    {
        if (isset($resource['total_price']) && is_numeric($resource['total_price'])) {
            return (float) $resource['total_price'];
        }

        return (float) ($resource['quantity'] ?? 0) * (float) ($resource['unit_price'] ?? 0);
    }
}
