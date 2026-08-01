<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

final readonly class ReportPublicationResolvedReleaseRequest
{
    public function __construct(
        public ReportPublicationReleaseAdmission $admission,
        public ReportPublicationReleaseEligibilityGate $gate,
    ) {}
}
