<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class ReportRunAttemptFinalizer
{
    public function __construct(private ReportRunAttemptLifecycleStore $store) {}

    public function finalize(string $runId, string $leaseToken, ?Throwable $failure, DateTimeImmutable $occurredAt): bool
    {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $runId) !== 1 || ! Str::isUuid($leaseToken) || $failure === null) {
            throw new InvalidArgumentException('report_run_attempt_finalization_invalid');
        }

        $errorCode = $failure instanceof ReportContractException
            ? $failure->errorCode
            : ReportErrorCode::REPORT_INTERNAL_ERROR;

        return $this->store->failLeased($runId, $leaseToken, $errorCode, $occurredAt);
    }
}
