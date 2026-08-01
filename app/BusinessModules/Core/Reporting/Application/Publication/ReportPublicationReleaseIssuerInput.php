<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

final readonly class ReportPublicationReleaseIssuerInput
{
    /** @param array<string, string> $artifactFiles */
    public function __construct(
        public string $trustedRoot,
        public ReportPublicationReleaseRequest $request,
        public ReportPublicationReleaseDispatchProfile $profile,
        public array $artifactFiles,
    ) {}
}
