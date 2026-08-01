<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;

interface ReportPublicationReleaseIngestor
{
    /** @param int[] $organizationAllowlist @param int[] $userAllowlist */
    public function ingest(
        string $proofPath,
        string $artifactPath,
        string $trustedDirectory,
        ReportPublicationReleaseAdmission $admission,
        string $expectedCommitSha,
        ReportPublicationFeatureMode $mode = ReportPublicationFeatureMode::OFF,
        array $organizationAllowlist = [],
        array $userAllowlist = [],
    ): PublishedReportDefinition;
}
