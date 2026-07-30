<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final readonly class ReportPrerequisiteEvidenceBundle
{
    /** @param list<ReportEvidenceArtifactDescriptor> $artifacts */
    public function __construct(public Sha256Hash $manifestHash, public array $artifacts, public array $planOneACompletion, public array $planOneBCompletion) {}
}
