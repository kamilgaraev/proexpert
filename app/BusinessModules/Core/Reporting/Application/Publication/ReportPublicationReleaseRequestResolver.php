<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

interface ReportPublicationReleaseRequestResolver
{
    public function resolve(ReportPublicationReleaseRequest $request): ReportPublicationResolvedReleaseRequest;
}
