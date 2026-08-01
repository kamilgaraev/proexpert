<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final class ProjectModelMerger
{
    private const ASSERTION_TYPES = [
        'area' => 'room',
        'dimension' => 'dimension',
        'room_purpose' => 'room',
        'opening' => 'opening',
    ];

    public function __construct(private readonly ProjectModelConflictResolver $conflictResolver = new ProjectModelConflictResolver) {}

    public function merge(
        ProjectModelEntityList $entities,
        ProjectModelAssertionList $assertions,
        ProjectModelCorrectionList $corrections,
        ProjectModelEvidenceBindingList $evidenceBindings,
    ): ProjectModelMergeResult
    {
        $entityIndex = $this->indexEntities($entities);
        $assertionIndex = $this->indexAssertions($assertions, $entityIndex);
        $this->validateEvidenceBindings($evidenceBindings, $entityIndex, $assertionIndex);
        $candidatesBySubject = $this->assertionCandidates($assertionIndex, $evidenceBindings);
        $this->appendCorrectionCandidates($candidatesBySubject, $corrections, $assertionIndex, $evidenceBindings);

        $resolved = [];
        $conflicts = [];
        $unconfirmed = [];
        ksort($candidatesBySubject, SORT_STRING);
        foreach ($candidatesBySubject as $subject => $candidates) {
            [$entityStableKey, $assertionType] = explode('|', $subject, 2);
            $confirmed = array_values(array_filter($candidates, static fn (ProjectModelCandidate $candidate): bool => $candidate->hasCanonicalConfirmation()));
            if ($confirmed === []) {
                $unconfirmed[] = new ProjectModelConflict(
                    $entityStableKey,
                    $assertionType,
                    self::conflictCode($assertionType, false),
                    self::stableKeys($candidates),
                );

                continue;
            }
            $outcome = $this->conflictResolver->resolve($entityStableKey, $assertionType, ProjectModelCandidateList::of(...$confirmed));
            if ($outcome instanceof ProjectModelConflict) {
                $conflicts[] = $outcome;

                continue;
            }
            $resolved[] = $outcome;
        }

        return ProjectModelMergeResult::fromResolution(
            ProjectModelResolvedValueList::of(...$resolved),
            ProjectModelConflictList::of(...$conflicts),
            ProjectModelConflictList::of(...$unconfirmed),
        );
    }

    public static function conflictCode(string $assertionType, bool $conflict): string
    {
        if (! array_key_exists($assertionType, self::ASSERTION_TYPES)) {
            throw new InvalidArgumentException('Project model assertion type is unsupported.');
        }

        return $assertionType.'_'.($conflict ? 'conflict' : 'unconfirmed');
    }

    private function indexEntities(ProjectModelEntityList $entities): array
    {
        $index = [];
        foreach ($entities as $entity) {
            if (! $entity instanceof ProjectModelEntity) {
                throw new InvalidArgumentException('Project model entity is invalid.');
            }
            if (isset($index[$entity->stableKey])) {
                throw new InvalidArgumentException('Project model entity key is duplicated.');
            }
            $index[$entity->stableKey] = $entity;
        }

        return $index;
    }

    private function indexAssertions(ProjectModelAssertionList $assertions, array $entityIndex): array
    {
        $index = [];
        foreach ($assertions as $assertion) {
            if (! $assertion instanceof ProjectModelAssertion) {
                throw new InvalidArgumentException('Project model assertion is invalid.');
            }
            $entity = $entityIndex[$assertion->entityStableKey] ?? null;
            if (! $entity instanceof ProjectModelEntity) {
                throw new InvalidArgumentException('Project model assertion references an unknown entity.');
            }
            $this->assertSameScope($entity, $assertion);
            $this->assertSupportedSubject($entity, $assertion);
            if (isset($index[$assertion->stableKey])) {
                throw new InvalidArgumentException('Project model assertion key is duplicated.');
            }
            $index[$assertion->stableKey] = $assertion;
        }

        return $index;
    }

    private function validateEvidenceBindings(ProjectModelEvidenceBindingList $evidenceBindings, array $entityIndex, array $assertionIndex): void
    {
        foreach ($evidenceBindings as $binding) {
            $entity = $entityIndex[$binding->entityStableKey] ?? null;
            if (! $entity instanceof ProjectModelEntity) {
                throw new InvalidArgumentException('Project model evidence binding references an unknown entity.');
            }
            $this->assertSameScope($entity, $binding);
            $assertion = $assertionIndex[$binding->assertionStableKey] ?? null;
            if (! $assertion instanceof ProjectModelAssertion || $assertion->entityStableKey !== $binding->entityStableKey) {
                throw new InvalidArgumentException('Project model evidence binding does not target an assertion subject.');
            }
            $this->assertSameScope($assertion, $binding);
        }
    }

    private function assertionCandidates(array $assertionIndex, ProjectModelEvidenceBindingList $evidenceBindings): array
    {
        $candidates = [];
        foreach ($assertionIndex as $assertion) {
            $source = $assertion->payload['source'] ?? null;
            if (! is_string($source) || ! in_array($source, ['cad', 'table', 'explicit_dimension', 'reconciled_geometry', 'ai_candidate'], true)) {
                throw new InvalidArgumentException('Project model assertion source is invalid.');
            }
            $value = $assertion->payload;
            unset($value['source']);
            $this->assertValue($assertion->assertionType, $value);
            $subject = $assertion->entityStableKey.'|'.$assertion->assertionType;
            $candidates[$subject][] = ProjectModelCandidate::forAssertion($assertion, $source, $value, $evidenceBindings);
        }

        return $candidates;
    }

    private function appendCorrectionCandidates(array &$candidatesBySubject, ProjectModelCorrectionList $corrections, array $assertionIndex, ProjectModelEvidenceBindingList $evidenceBindings): void
    {
        $keys = [];
        foreach ($corrections as $correction) {
            if (! $correction instanceof ProjectModelCorrection) {
                throw new InvalidArgumentException('Project model correction is invalid.');
            }
            if (isset($keys[$correction->stableKey])) {
                throw new InvalidArgumentException('Project model correction key is duplicated.');
            }
            $keys[$correction->stableKey] = true;
            $assertion = $assertionIndex[$correction->assertionStableKey] ?? null;
            if (! $assertion instanceof ProjectModelAssertion) {
                throw new InvalidArgumentException('Project model correction references an unknown assertion.');
            }
            $this->assertSameScope($assertion, $correction);
            $this->assertValue($assertion->assertionType, $correction->payload);
            $isManual = $correction->correctionType === 'manual';
            $subject = $assertion->entityStableKey.'|'.$assertion->assertionType;
            $candidatesBySubject[$subject][] = ProjectModelCandidate::forCorrection(
                $assertion,
                $correction,
                $isManual ? 'manual_correction' : 'reconciled_geometry',
                $correction->payload,
                $evidenceBindings,
            );
        }
    }

    private function assertSupportedSubject(ProjectModelEntity $entity, ProjectModelAssertion $assertion): void
    {
        if (! isset(self::ASSERTION_TYPES[$assertion->assertionType])
            || self::ASSERTION_TYPES[$assertion->assertionType] !== $entity->kind) {
            throw new InvalidArgumentException('Project model assertion subject is invalid.');
        }
    }

    private function assertValue(string $assertionType, array $value): void
    {
        $valid = match ($assertionType) {
            'area' => self::isPositiveNumber($value['value'] ?? null) && ($value['unit'] ?? null) === 'm2' && count($value) === 2,
            'dimension' => self::isPositiveNumber($value['value'] ?? null)
                && is_string($value['unit'] ?? null)
                && in_array($value['unit'], ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'], true)
                && count($value) === 2,
            'room_purpose' => is_string($value['value'] ?? null) && trim($value['value']) !== '' && count($value) === 1,
            'opening' => in_array($value['type'] ?? null, ['door', 'window', 'gate'], true)
                && self::isPositiveNumber($value['width_m'] ?? null)
                && self::isPositiveNumber($value['height_m'] ?? null)
                && count($value) === 3,
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Project model assertion value is invalid.');
        }
    }

    private function assertSameScope(object $left, object $right): void
    {
        foreach (['buildingModelId', 'organizationId', 'projectId', 'sessionId', 'sourceVersion'] as $property) {
            if ($left->{$property} !== $right->{$property}) {
                throw new InvalidArgumentException('Project model records do not share a scope.');
            }
        }
    }

    private static function isPositiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }

    private static function stableKeys(array $candidates): array
    {
        $keys = array_map(static fn (ProjectModelCandidate $candidate): string => $candidate->stableKey, $candidates);
        sort($keys, SORT_STRING);

        return array_values(array_unique($keys));
    }
}
