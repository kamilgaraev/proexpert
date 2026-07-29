<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;

interface ReportSourceBackfill
{
    public function sourceCode(): string;

    public function sourceSchemaVersion(): string;

    public function nextBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): ReportSourceBackfillBatch;

    public function apply(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): ReportSourceBackfillResult;
}
