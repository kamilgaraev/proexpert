<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class ReportPublicationReleaseBundle
{
    public function __construct(
        public ReportPublicationProof $proof,
        public string $artifactBytes,
        public string $artifactName,
    ) {}
}
