<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelSchema;
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

    public function merge(array $entities, array $assertions, array $corrections, array $evidenceBindings): ProjectModelMergeResult
    {
        $entityIndex = $this->indexEntities($entities);
        $assertionIndex = $this->indexAssertions($assertions, $entityIndex);
        $evidencedEntityKeys = $this->evidencedEntityKeys($evidenceBindings, $entityIndex);
        $candidatesBySubject = $this->assertionCandidates($assertionIndex, $evidencedEntityKeys);
        $this->appendCorrectionCandidates($candidatesBySubject, $corrections, $assertionIndex, $evidencedEntityKeys);

        $resolved = [];
        $conflicts = [];
        $unconfirmed = [];
        ksort($candidatesBySubject, SORT_STRING);
        foreach ($candidatesBySubject as $subject => $candidates) {
            [$entityStableKey, $assertionType] = explode('|', $subject, 2);
            $confirmed = array_values(array_filter($candidates, static fn (ProjectModelCandidate $candidate): bool => $candidate->confirmed));
            if ($confirmed === []) {
                $unconfirmed[] = new ProjectModelConflict(
                    $entityStableKey,
                    $assertionType,
                    self::conflictCode($assertionType, false),
                    self::stableKeys($candidates),
                );

                continue;
            }
            $outcome = $this->conflictResolver->resolve($entityStableKey, $assertionType, $confirmed);
            if ($outcome instanceof ProjectModelConflict) {
                $conflicts[] = $outcome;

                continue;
            }
            $resolved[] = $outcome;
        }

        return new ProjectModelMergeResult($resolved, $conflicts, $unconfirmed);
    }

    public static function canonicalValue(array $value): string
    {
        return BuildingModelSchema::canonicalJson($value);
    }

    public static function conflictCode(string $assertionType, bool $conflict): string
    {
        if (! array_key_exists($assertionType, self::ASSERTION_TYPES)) {
            throw new InvalidArgumentException('Project model assertion type is unsupported.');
        }

        return $assertionType.'_'.($conflict ? 'conflict' : 'unconfirmed');
    }

    private function indexEntities(array $entities): array
    {
        if (! array_is_list($entities)) {
            throw new InvalidArgumentException('Project model entities must be a list.');
        }
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

    private function indexAssertions(array $assertions, array $entityIndex): array
    {
        if (! array_is_list($assertions)) {
            throw new InvalidArgumentException('Project model assertions must be a list.');
        }
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

    private function evidencedEntityKeys(array $evidenceBindings, array $entityIndex): array
    {
        if (! array_is_list($evidenceBindings)) {
            throw new InvalidArgumentException('Project model evidence bindings must be a list.');
        }
        $keys = [];
        foreach ($evidenceBindings as $binding) {
            if (! $binding instanceof ProjectModelEvidenceBinding) {
                throw new InvalidArgumentException('Project model evidence binding is invalid.');
            }
            $entity = $entityIndex[$binding->entityStableKey] ?? null;
            if (! $entity instanceof ProjectModelEntity) {
                throw new InvalidArgumentException('Project model evidence binding references an unknown entity.');
            }
            $this->assertSameScope($entity, $binding);
            $keys[$binding->entityStableKey] = true;
        }

        return $keys;
    }

    private function assertionCandidates(array $assertionIndex, array $evidencedEntityKeys): array
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
            $candidates[$subject][] = new ProjectModelCandidate(
                $assertion->stableKey,
                $assertion->stableKey,
                null,
                $source,
                $value,
                $source !== 'ai_candidate' && isset($evidencedEntityKeys[$assertion->entityStableKey]),
            );
        }

        return $candidates;
    }

    private function appendCorrectionCandidates(array &$candidatesBySubject, array $corrections, array $assertionIndex, array $evidencedEntityKeys): void
    {
        if (! array_is_list($corrections)) {
            throw new InvalidArgumentException('Project model corrections must be a list.');
        }
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
            $candidatesBySubject[$subject][] = new ProjectModelCandidate(
                $correction->stableKey,
                $assertion->stableKey,
                $correction->stableKey,
                $isManual ? 'manual_correction' : 'reconciled_geometry',
                $correction->payload,
                $isManual || isset($evidencedEntityKeys[$assertion->entityStableKey]),
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
