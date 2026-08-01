<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use Closure;

interface ReportReadyDownloadStore
{
    /** @param Closure(ReportExport, int): ReportDownloadLink $presign */
    public function withReadyDownload(
        ReportExecutionContext $context,
        string $exportId,
        int $requestedTtlSeconds,
        ReportAuthorizationFence $fence,
        Closure $presign,
    ): ReportDownloadLink;
}
