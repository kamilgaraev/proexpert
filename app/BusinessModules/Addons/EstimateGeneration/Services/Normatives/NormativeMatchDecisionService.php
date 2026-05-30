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

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $workItem
     */
    public function decide(array $candidate, array $workItem): NormativeMatchDecisionData
    {
        $confidence = (float) ($candidate['confidence'] ?? 0);
        $warnings = [];
        $reasons = [];
        $unitCompatible = $this->unitCompatible((string) ($candidate['unit'] ?? ''), (string) ($workItem['unit'] ?? ''));
        $scopeCompatible = $this->scopeCompatible($candidate, $workItem);
        $resourceCount = $this->resourcesCount($candidate['resources'] ?? []);
        $pricedCount = $this->pricedResourcesCount($candidate['resources'] ?? []);

        if (!$unitCompatible) {
            $warnings[] = 'unit_mismatch';
        } else {
            $reasons[] = 'unit_compatible';
        }

        if (!$scopeCompatible) {
            $warnings[] = 'scope_mismatch';
        } else {
            $reasons[] = 'scope_compatible';
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
                'norm_without_resources',
                'norm_without_prices',
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
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $workItem
     */
    private function scopeCompatible(array $candidate, array $workItem): bool
    {
        $intent = is_array($workItem['work_intent'] ?? null) ? $workItem['work_intent'] : [];
        $scope = (string) ($intent['scope'] ?? '');

        if ($scope === '') {
            return true;
        }

        $sectionCode = $this->candidateSectionCode($candidate);

        if (in_array($scope, ['engineering', 'roof', 'walls', 'facade', 'finishing'], true) && str_starts_with($sectionCode, '01')) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $candidate
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
     * @param array<string, mixed> $resources
     */
    private function resourcesCount(array $resources): int
    {
        return count($resources['materials'] ?? [])
            + count($resources['machinery'] ?? [])
            + count($resources['labor'] ?? [])
            + count($resources['other'] ?? []);
    }

    /**
     * @param array<string, mixed> $resources
     */
    private function pricedResourcesCount(array $resources): int
    {
        $count = 0;

        foreach ($resources as $group) {
            foreach ($group as $resource) {
                if (($resource['price_source'] ?? null) !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
