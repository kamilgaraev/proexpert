<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseArtifact;

interface ReportPublicationReleaseArtifactVerifier
{
    public function verify(string $artifactBytes): ReportPublicationReleaseArtifact;
}
