<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;

final readonly class CompositeReportSchedulingCapabilityRegistry implements ReportSchedulingCapabilityRegistry
{
    public function __construct(private ReportSchedulingCapabilityRegistry $builtins, private ReportSchedulingCapabilityRegistry $database) {}

    public function published(string $code): ReportSchedulingCapability
    {
        try {
            return $this->builtins->published($code);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode !== ReportErrorCode::REPORT_NOT_FOUND) {
                throw $exception;
            }

            return $this->database->published($code);
        }
    }
}
