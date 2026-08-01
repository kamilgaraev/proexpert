<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;

interface CandidateReportDefinitionRegistry
{
    public function candidate(string $code): CandidateReportDefinition;

    public function candidateCodes(): array;
}
