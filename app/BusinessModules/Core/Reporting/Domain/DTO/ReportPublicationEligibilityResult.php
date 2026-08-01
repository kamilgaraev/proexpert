<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class ReportPublicationEligibilityResult
{
    public function __construct(private EligibleReportPublication $publication) {}

    public function eligible(): bool
    {
        return true;
    }

    public function publication(): EligibleReportPublication
    {
        return $this->publication;
    }
}
