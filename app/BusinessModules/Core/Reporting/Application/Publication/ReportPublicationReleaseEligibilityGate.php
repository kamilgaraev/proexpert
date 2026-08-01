<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;

interface ReportPublicationReleaseEligibilityGate
{
    public function assertEligible(
        ReportPublicationReleaseAdmission $admission,
        ReportPublicationProof $proof,
        ReportPublicationReleaseIdentity $release,
        string $artifactBytes,
    ): void;
}
