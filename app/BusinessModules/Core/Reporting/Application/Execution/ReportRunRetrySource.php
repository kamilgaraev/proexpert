<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use InvalidArgumentException;

final readonly class ReportRunRetrySource
{
    public function __construct(
        public ReportRun $run,
        public ReportQuery $query,
        public ?ReportSavedViewRef $savedView,
        public ?ReportErrorCode $errorCode,
    ) {
        if (!in_array($run->status, [ReportRunStatus::FAILED, ReportRunStatus::CANCELLED, ReportRunStatus::EXPIRED], true)) {
            throw new InvalidArgumentException('report_run_retry_source_invalid');
        }
        if (($run->status === ReportRunStatus::FAILED) !== ($errorCode !== null)) {
            throw new InvalidArgumentException('report_run_retry_source_invalid');
        }
        if (
            !hash_equals($run->definitionHash->value, $query->definition->definitionHash->value)
            || !hash_equals($run->queryHash->value, $query->queryHash->value)
        ) {
            throw new InvalidArgumentException('report_run_retry_source_invalid');
        }
    }
}
