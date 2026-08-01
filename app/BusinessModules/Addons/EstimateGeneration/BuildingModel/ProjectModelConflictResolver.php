<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final class ProjectModelConflictResolver
{
    public function resolve(string $entityStableKey, string $assertionType, array $candidates): ProjectModelResolvedValue|ProjectModelConflict
    {
        if ($candidates === [] || ! array_is_list($candidates)) {
            throw new InvalidArgumentException('Project model candidates are invalid.');
        }
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof ProjectModelCandidate) {
                throw new InvalidArgumentException('Project model candidate is invalid.');
            }
        }
        usort($candidates, static fn (ProjectModelCandidate $left, ProjectModelCandidate $right): int => $right->priority() <=> $left->priority() ?: $left->stableKey <=> $right->stableKey);
        $priority = $candidates[0]->priority();
        $highest = array_values(array_filter($candidates, static fn (ProjectModelCandidate $candidate): bool => $candidate->priority() === $priority));
        $values = [];
        foreach ($highest as $candidate) {
            $values[ProjectModelMerger::canonicalValue($candidate->value)] = $candidate->value;
        }
        if (count($values) > 1) {
            $candidateStableKeys = array_map(static fn (ProjectModelCandidate $candidate): string => $candidate->stableKey, $highest);
            sort($candidateStableKeys, SORT_STRING);
            $conflictingValues = array_values($values);
            usort($conflictingValues, static fn (array $left, array $right): int => ProjectModelMerger::canonicalValue($left) <=> ProjectModelMerger::canonicalValue($right));

            return new ProjectModelConflict(
                $entityStableKey,
                $assertionType,
                ProjectModelMerger::conflictCode($assertionType, true),
                $candidateStableKeys,
                $conflictingValues,
            );
        }
        $candidate = $highest[0];

        return new ProjectModelResolvedValue(
            $entityStableKey,
            $assertionType,
            $candidate->value,
            $candidate->source,
            $candidate->assertionStableKey,
            $candidate->correctionStableKey,
        );
    }
}
