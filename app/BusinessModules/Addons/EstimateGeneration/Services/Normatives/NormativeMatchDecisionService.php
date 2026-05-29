<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\NormativeMatchDecisionData;

class NormativeMatchDecisionService
{
    private const ACCEPT_CONFIDENCE_THRESHOLD = 0.72;
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
        $resourceCount = $this->resourcesCount($candidate['resources'] ?? []);
        $pricedCount = $this->pricedResourcesCount($candidate['resources'] ?? []);

        if (!$unitCompatible) {
            $warnings[] = 'unit_mismatch';
        } else {
            $reasons[] = 'unit_compatible';
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

        if ($unitCompatible && $confidence >= self::ACCEPT_CONFIDENCE_THRESHOLD && $resourceCount > 0 && $pricedCount > 0) {
            return new NormativeMatchDecisionData('accepted', true, $confidence, $reasons, $warnings, $candidate);
        }

        if ($resourceCount > 0 && $pricedCount > 0) {
            return new NormativeMatchDecisionData('candidate', true, $confidence, $reasons, $warnings, $candidate);
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
