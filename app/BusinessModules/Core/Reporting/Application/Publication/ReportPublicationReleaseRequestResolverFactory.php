<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

interface ReportPublicationReleaseRequestResolverFactory
{
    public function create(string $trustedDirectory): ReportPublicationReleaseRequestResolver;
}
