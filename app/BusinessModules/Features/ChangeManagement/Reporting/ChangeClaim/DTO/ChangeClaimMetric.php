<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO;

final readonly class ChangeClaimMetric
{
    public function __construct(
        public string $currency,
        public int $proposedExposureMinor,
        public int $approvedExposureMinor,
        public int $linkedClaimMinor,
        public int $openingContingencyMinor,
        public int $allocatedContingencyMinor,
        public int $consumedContingencyMinor,
        public int $releasedContingencyMinor,
        public int $closingContingencyMinor,
    ) {
    }
}
