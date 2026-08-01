<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

interface ReportConformanceEvidenceRepository
{
    public function get(
        string $code,
        Sha256Hash $definitionHash,
        Sha256Hash $fixtureHash,
    ): ReportDefinitionConformanceEvidence;

    public function put(ReportDefinitionConformanceEvidence $evidence): void;
}
