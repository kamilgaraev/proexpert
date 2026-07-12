<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

use DateTimeImmutable;

final readonly class NormativeCandidateData
{
    /** @param list<string> $sourceEvidence */
    public function __construct(
        public string $id,
        public int $normativeId,
        public int $datasetId,
        public string $datasetVersion,
        public string $datasetStatus,
        public string $code,
        public string $name,
        public ?string $canonicalUnit,
        public ?string $unitDimension,
        public ?string $material,
        public ?string $technology,
        public ?string $structure,
        public ?string $normativeSection,
        public ?string $objectType,
        public ?string $regionCode,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public float $lexicalScore,
        public ?float $semanticScore,
        public string $lexicalAlgorithmVersion,
        public ?string $semanticIndexVersion,
        public array $sourceEvidence,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
