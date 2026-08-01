<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

interface ReportPublicationReleaseCandidateResolver
{
    /** @return array<string, array<string, mixed>> */
    public function resolve(string $trustedDirectory, ReportPublicationReleaseRequest $request): array;
}
