<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final readonly class EligibilityServiceReportPublicationReleaseGate implements ReportPublicationReleaseEligibilityGate
{
    public function __construct(private ReportPublicationEligibilityService $eligibility) {}

    public function assertEligible(
        ReportPublicationReleaseAdmission $admission,
        ReportPublicationProof $proof,
        ReportPublicationReleaseIdentity $release,
        string $artifactBytes,
    ): void {
        $this->eligibility->evaluate(
            $admission->candidate,
            $admission->candidateDocument,
            $admission->binding,
            $admission->evidence,
            $proof,
            new Sha256Hash(hash('sha256', $admission->candidateManifestBytes)),
            new Sha256Hash(hash('sha256', $admission->officialManifestBytes)),
            $release,
            $artifactBytes,
            $admission->previous,
        );
    }
}
