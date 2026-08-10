<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final class ProjectModelConflictResolver
{
    public function resolve(string $entityStableKey, string $assertionType, ProjectModelCandidateList $candidates): ProjectModelResolvedValue|ProjectModelConflict
    {
        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException('Project model candidates are invalid.');
        }
        $items = iterator_to_array($candidates, false);
        usort($items, static fn (ProjectModelCandidate $left, ProjectModelCandidate $right): int => $right->priority() <=> $left->priority() ?: $left->stableKey <=> $right->stableKey);
        $manual = array_values(array_filter(
            $items,
            static fn (ProjectModelCandidate $candidate): bool => $candidate->source === 'manual_correction',
        ));
        $considered = $manual === [] ? $items : $manual;
        $values = [];
        foreach ($considered as $candidate) {
            $values[ProjectModelValueFingerprint::for($candidate->value)] = $candidate->value;
        }
        if (count($values) > 1) {
            $candidateStableKeys = array_map(static fn (ProjectModelCandidate $candidate): string => $candidate->stableKey, $considered);
            sort($candidateStableKeys, SORT_STRING);
            $conflictingValues = array_values($values);
            usort($conflictingValues, static fn (array $left, array $right): int => ProjectModelValueFingerprint::for($left) <=> ProjectModelValueFingerprint::for($right));

            return new ProjectModelConflict(
                $entityStableKey,
                $assertionType,
                ProjectModelMerger::conflictCode($assertionType, true),
                $candidateStableKeys,
                $conflictingValues,
            );
        }
        $candidate = $considered[0];

        return ProjectModelResolvedValue::fromConfirmedCandidate($entityStableKey, $assertionType, $candidate);
    }
}
