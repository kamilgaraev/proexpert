<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelCandidate
{
    public function __construct(
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

    public function priority(): int
    {
        return match ($this->source) {
            'manual_correction' => 4,
            'cad', 'table', 'explicit_dimension' => 3,
            'reconciled_geometry' => 2,
            'ai_candidate' => 1,
        };
    }
}
