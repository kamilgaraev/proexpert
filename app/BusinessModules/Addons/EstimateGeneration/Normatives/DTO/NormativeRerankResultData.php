<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

final readonly class NormativeRerankResultData
{
    /** @param list<string> $ordering @param list<string> $explanationCodes @param list<string> $evidenceRefs */
    public function __construct(
        public ?string $selectedCandidateId,
        public array $ordering,
        public array $explanationCodes,
        public array $evidenceRefs,
        public float $confidence,
        public string $status,
        public string $schemaVersion,
        public string $provider,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
