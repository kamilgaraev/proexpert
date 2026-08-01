<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final class ProjectReportPublicationReleaseRequestRegistry
{
    public function resolve(ReportPublicationReleaseRequest $request): ReportPublicationResolvedReleaseRequest
    {
        throw new InvalidArgumentException('report_publication_release_request_not_registered');
    }
}
