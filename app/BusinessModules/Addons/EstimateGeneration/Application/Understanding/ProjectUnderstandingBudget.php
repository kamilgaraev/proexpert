<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use InvalidArgumentException;

final readonly class ProjectUnderstandingBudget
{
    public function __construct(
        public int $maxFacts,
        public int $maxGroups,
        public int $maxCandidatesTotal,
        public int $maxCandidatesPerGroup,
        public int $maxLinks,
        public int $maxProviderCalls,
        public int $maxEvidenceItems,
        public int $maxEvidencePayloadBytes,
        public int $maxEvidenceBytesPerItem = 262_144,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Project understanding budget is invalid.');
            }
        }
    }

    public static function defaults(int $maxCandidatesPerGroup = 20): self
    {
        return new self(2_000, 500, 4_000, $maxCandidatesPerGroup, 1_000, 20, 4_000, 2_000_000);
    }
}
