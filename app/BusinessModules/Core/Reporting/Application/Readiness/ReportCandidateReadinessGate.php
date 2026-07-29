<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Readiness;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;

final readonly class ReportCandidateReadinessGate
{
    public function assertReady(string $reportCode, ReportSourceReadiness $readiness): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $reportCode) !== 1 || ! $readiness->isReady()) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
                ['fields' => 'report_code'],
            );
        }
    }
}
