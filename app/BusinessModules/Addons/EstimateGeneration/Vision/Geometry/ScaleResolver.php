<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final class ScaleResolver
{
    public function resolve(array $vectorDimensions, array $visionDimensions, ?ControlDimensionData $userControlDimension): ScaleResolutionData
    {
        $this->assertCandidates($vectorDimensions, 'vector');
        $this->assertCandidates($visionDimensions, 'vision');
        $all = [...$vectorDimensions, ...$visionDimensions];
        if ($userControlDimension !== null) {
            foreach ($all as $candidate) {
                if ($candidate->contextKey() !== $userControlDimension->contextKey()) {
                    return $this->conflict($all, $userControlDimension);
                }
            }
        }
        $values = array_map(static fn (ScaleCandidateData $item): float => $item->metersPerUnit, $all);
        if ($userControlDimension !== null) {
            $values[] = $userControlDimension->metersPerUnit;
        }
        if (! $this->consistent($values)) {
            return $this->conflict($all, $userControlDimension);
        }

        if ($vectorDimensions !== []) {
            return $this->confirmed($vectorDimensions);
        }
        if ($userControlDimension !== null) {
            return new ScaleResolutionData('confirmed', $userControlDimension->metersPerUnit, [$userControlDimension->evidenceRef], null);
        }
        $uniqueVision = [];
        foreach ($visionDimensions as $candidate) {
            $uniqueVision[$candidate->evidenceRef] = $candidate;
        }
        if (count($uniqueVision) >= 2) {
            return $this->confirmed(array_values($uniqueVision));
        }

        return new ScaleResolutionData('missing', null, [], 'geometry_scale_unconfirmed');
    }

    private function assertCandidates(array $candidates, string $source): void
    {
        if (! array_is_list($candidates)) {
            throw new InvalidArgumentException('Scale candidates must be a list.');
        }
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof ScaleCandidateData || $candidate->source !== $source) {
                throw new InvalidArgumentException('Scale candidate source is invalid.');
            }
        }
    }

    private function consistent(array $values): bool
    {
        for ($left = 0; $left < count($values); $left++) {
            for ($right = $left + 1; $right < count($values); $right++) {
                $minimum = min($values[$left], $values[$right]);
                if (abs($values[$left] - $values[$right]) > $minimum * 0.02 + 1.0e-12) {
                    return false;
                }
            }
        }

        return true;
    }

    private function confirmed(array $candidates): ScaleResolutionData
    {
        usort($candidates, static fn (ScaleCandidateData $a, ScaleCandidateData $b): int => $a->evidenceRef <=> $b->evidenceRef);
        $evidence = array_values(array_unique(array_map(static fn (ScaleCandidateData $item): string => $item->evidenceRef, $candidates)));
        $scale = array_sum(array_map(static fn (ScaleCandidateData $item): float => $item->metersPerUnit, $candidates)) / count($candidates);

        return new ScaleResolutionData('confirmed', $scale, $evidence, null);
    }

    private function conflict(array $candidates, ?ControlDimensionData $control): ScaleResolutionData
    {
        $evidence = array_map(static fn (ScaleCandidateData $item): string => $item->evidenceRef, $candidates);
        if ($control !== null) {
            $evidence[] = $control->evidenceRef;
        }
        $evidence = array_values(array_unique($evidence));
        sort($evidence, SORT_STRING);

        return new ScaleResolutionData('conflict', null, $evidence, 'geometry_scale_conflict');
    }
}
