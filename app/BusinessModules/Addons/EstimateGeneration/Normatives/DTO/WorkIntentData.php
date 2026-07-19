<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

use DateTimeImmutable;

final readonly class WorkIntentData
{
    /**
     * @param  list<string>  $sourceEvidence
     * @param  list<string>  $normativeSections
     * @param  list<array<string, mixed>>  $specializationEvidence
     * @param  array<string, mixed>|null  $specializationScenario
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $workItemId,
        public string $intent,
        public string $canonicalUnit,
        public string $unitDimension,
        public string $material,
        public string $technology,
        public string $structure,
        public string $normativeSection,
        public string $objectType,
        public string $datasetVersion,
        public string $datasetStatus,
        public ?string $regionCode,
        public DateTimeImmutable $applicabilityDate,
        public array $sourceEvidence,
        public array $normativeSections = [],
        public ?string $requestedNormativeCode = null,
        public string $system = '',
        public string $workObject = '',
        public array $specializationEvidence = [],
        public ?array $specializationScenario = null,
    ) {
        EvidenceBounds::assert($sourceEvidence);
    }
}
