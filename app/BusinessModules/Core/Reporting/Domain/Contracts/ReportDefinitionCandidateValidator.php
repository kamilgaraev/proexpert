<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;

interface ReportDefinitionCandidateValidator
{
    public function validate(CandidateReportDefinitionRegistry $registry, iterable $bindings): ReportCandidateValidationResult;
}
