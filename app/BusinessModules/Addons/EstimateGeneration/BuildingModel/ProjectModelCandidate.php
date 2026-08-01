<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelCandidate
{
    private function __construct(
        public string $stableKey,
        public string $assertionStableKey,
        public ?string $correctionStableKey,
        public string $source,
        public array $value,
        public bool $confirmed,
    ) {
        ProjectModelEntity::assertStableKey($stableKey, 'Project model candidate');
        ProjectModelEntity::assertStableKey($assertionStableKey, 'Project model candidate assertion');
        if ($correctionStableKey !== null) {
            ProjectModelEntity::assertStableKey($correctionStableKey, 'Project model candidate correction');
        }
        if (! in_array($source, ['manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry', 'ai_candidate'], true)) {
            throw new InvalidArgumentException('Project model candidate source is invalid.');
        }
        ProjectModelEntity::assertObject($value, 'Project model candidate value');
        if ($value === []) {
            throw new InvalidArgumentException('Project model candidate value cannot be empty.');
        }
        if ($source === 'ai_candidate' && $confirmed) {
            throw new InvalidArgumentException('AI candidate cannot be confirmed.');
        }
    }

    /** @param array<string, mixed> $value */
    public static function forAssertion(ProjectModelAssertion $assertion, string $source, array $value, ProjectModelEvidenceBindingList $bindings): self
    {
        return new self(
            $assertion->stableKey,
            $assertion->stableKey,
            null,
            $source,
            $value,
            $source !== 'ai_candidate' && self::hasExactEvidence($bindings, $assertion->entityStableKey, $assertion->stableKey, null, $source, $value),
        );
    }

    /** @param array<string, mixed> $value */
    public static function forCorrection(ProjectModelAssertion $assertion, ProjectModelCorrection $correction, string $source, array $value, ProjectModelEvidenceBindingList $bindings): self
    {
        return new self(
            $correction->stableKey,
            $assertion->stableKey,
            $correction->stableKey,
            $source,
            $value,
            $source === 'manual_correction' || self::hasExactEvidence($bindings, $assertion->entityStableKey, $assertion->stableKey, $correction->stableKey, $source, $value),
        );
    }

    public function hasCanonicalConfirmation(): bool
    {
        return $this->confirmed && $this->source !== 'ai_candidate';
    }

    public function priority(): int
    {
        return match ($this->source) {
            'manual_correction' => 4,
            'cad', 'table', 'explicit_dimension' => 3,
            'reconciled_geometry' => 2,
            'ai_candidate' => 1,
        };
    }

    /** @param array<string, mixed> $value */
    private static function hasExactEvidence(ProjectModelEvidenceBindingList $bindings, string $entityStableKey, string $assertionStableKey, ?string $correctionStableKey, string $source, array $value): bool
    {
        foreach ($bindings as $binding) {
            if ($binding->proves($entityStableKey, $assertionStableKey, $correctionStableKey, $source, $value)) {
                return true;
            }
        }

        return false;
    }
}
