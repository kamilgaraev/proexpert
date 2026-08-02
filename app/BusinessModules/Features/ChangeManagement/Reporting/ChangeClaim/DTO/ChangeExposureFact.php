<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO;

use DomainException;

final readonly class ChangeExposureFact
{
    public function __construct(
        public int $changeRequestId,
        public int $changeVersion,
        public int $projectId,
        public int $allocationId,
        public string $currency,
        public int $proposedMinor,
        public ?int $approvedMinor,
        public array $linkedClaims,
    ) {
        if ($changeRequestId < 1 || $changeVersion < 1 || $projectId < 1 || $allocationId < 1
            || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('change_exposure_fact_invalid');
        }
    }
}
