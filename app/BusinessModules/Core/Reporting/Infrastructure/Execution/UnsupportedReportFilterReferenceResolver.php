<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;

final readonly class UnsupportedReportFilterReferenceResolver implements ReportFilterReferenceResolver
{
    public function resolve(ReportScope $scope, string $filter, int|string $value): int|string
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
    }
}
