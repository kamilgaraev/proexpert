<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelResolvedValue
{
    private function __construct(
        public string $entityStableKey,
        public string $assertionType,
        public array $value,
        public string $source,
        public string $assertionStableKey,
        public ?string $correctionStableKey = null,
        private bool $confirmed = false,
    ) {
        ProjectModelEntity::assertStableKey($entityStableKey, 'Resolved value entity');
        self::assertAssertionType($assertionType);
        ProjectModelEntity::assertObject($value, 'Resolved value');
        if ($value === []) {
            throw new InvalidArgumentException('Resolved value cannot be empty.');
        }
        if (! in_array($source, ['manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry'], true)) {
            throw new InvalidArgumentException('Resolved value source is invalid.');
        }
        ProjectModelEntity::assertStableKey($assertionStableKey, 'Resolved value assertion');
        if ($correctionStableKey !== null) {
            ProjectModelEntity::assertStableKey($correctionStableKey, 'Resolved value correction');
        }
    }

    public static function fromConfirmedCandidate(string $entityStableKey, string $assertionType, ProjectModelCandidate $candidate): self
    {
        if (! $candidate->hasCanonicalConfirmation()) {
            throw new InvalidArgumentException('Resolved value requires a confirmed canonical candidate.');
        }

        return new self(
            $entityStableKey,
            $assertionType,
            $candidate->value,
            $candidate->source,
            $candidate->assertionStableKey,
            $candidate->correctionStableKey,
            true,
        );
    }

    public function hasConfirmedCanonicalProof(): bool
    {
        return $this->confirmed && $this->source !== 'ai_candidate';
    }

    public static function assertAssertionType(string $assertionType): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $assertionType) !== 1) {
            throw new InvalidArgumentException('Resolved value assertion type is invalid.');
        }
    }
}
