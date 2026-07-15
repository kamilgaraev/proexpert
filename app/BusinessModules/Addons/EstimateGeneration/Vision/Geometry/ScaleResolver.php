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
        $vectors = $this->deduplicate($vectorDimensions);
        $visions = $this->deduplicate($visionDimensions);
        if (! $this->validGroup($vectors)) {
            return $this->conflict($vectors, null);
        }
        $confirmedVision = count($visions) >= 2 ? $visions : [];
        if ($confirmedVision !== [] && ! $this->validGroup($confirmedVision)) {
            return $this->conflict($confirmedVision, null);
        }
        $participating = [...$vectors, ...$confirmedVision];
        $contexts = array_map(static fn (ScaleCandidateData $item): string => $item->contextKey(), $participating);
        $values = array_map(static fn (ScaleCandidateData $item): float => $item->metersPerUnit, $participating);
        if ($userControlDimension !== null) {
            $contexts[] = $userControlDimension->contextKey();
            $values[] = $userControlDimension->metersPerUnit;
        }
        if (count(array_unique($contexts)) > 1 || ! $this->consistent($values)) {
            return $this->conflict($participating, $userControlDimension);
        }

        if ($vectors !== []) {
            return $this->confirmed($vectors);
        }
        if ($userControlDimension !== null) {
            return new ScaleResolutionData('confirmed', $userControlDimension->metersPerUnit, [$userControlDimension->evidenceRef], null, $userControlDimension->context());
        }
        if ($confirmedVision !== []) {
            return $this->confirmed($confirmedVision);
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
                $maximum = max($values[$left], $values[$right]);
                if ($maximum / $minimum > 1.02) {
                    return false;
                }
            }
        }

        return true;
    }

    private function deduplicate(array $candidates): array
    {
        $unique = [];
        $ambiguous = [];
        foreach ($candidates as $candidate) {
            if (isset($ambiguous[$candidate->evidenceRef])) {
                continue;
            }
            if (isset($unique[$candidate->evidenceRef])) {
                $existing = $unique[$candidate->evidenceRef];
                if ($existing->source !== $candidate->source
                    || $existing->contextKey() !== $candidate->contextKey()
                    || ! $this->consistent([$existing->metersPerUnit, $candidate->metersPerUnit])) {
                    unset($unique[$candidate->evidenceRef]);
                    $ambiguous[$candidate->evidenceRef] = true;

                    continue;
                }
                if ($candidate->confidence > $existing->confidence) {
                    $unique[$candidate->evidenceRef] = $candidate;
                }

                continue;
            }
            $unique[$candidate->evidenceRef] = $candidate;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    private function validGroup(array $candidates): bool
    {
        return $candidates === [] || (
            count(array_unique(array_map(static fn (ScaleCandidateData $item): string => $item->contextKey(), $candidates))) === 1
            && $this->consistent(array_map(static fn (ScaleCandidateData $item): float => $item->metersPerUnit, $candidates))
        );
    }

    private function confirmed(array $candidates): ScaleResolutionData
    {
        usort($candidates, static fn (ScaleCandidateData $a, ScaleCandidateData $b): int => $a->evidenceRef <=> $b->evidenceRef);
        $evidence = array_values(array_unique(array_map(static fn (ScaleCandidateData $item): string => $item->evidenceRef, $candidates)));
        $scale = array_sum(array_map(static fn (ScaleCandidateData $item): float => $item->metersPerUnit, $candidates)) / count($candidates);

        return new ScaleResolutionData('confirmed', $scale, $evidence, null, $candidates[0]->context());
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
