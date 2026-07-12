<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

final readonly class RejectedNormativeCandidateData
{
    /** @param list<string> $reasonCodes @param array<string, mixed> $evidence */
    public function __construct(public NormativeCandidateData $candidate, public array $reasonCodes, public array $evidence) {}
}
