<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class FactoryInspectionClaim
{
    public function __construct(
        public string $runId,
        public string $checkpointId,
        public string $ownerToken,
        public int $attempt,
        public string $inputFingerprint,
        public string $modelToken,
        public EstimateGenerationSession $session,
        public ProjectModelSnapshot $snapshot,
        public string $stepKind = 'inspect_input',
        public ?string $sourceCheckpointId = null,
    ) {}
}
