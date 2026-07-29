<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class ReportExportAttemptFinalizer
{
    public function __construct(private ReportExportAttemptLifecycleStore $store) {}

    public function finalize(
        string $exportId,
        string $leaseToken,
        ?Throwable $failure,
        DateTimeImmutable $occurredAt,
    ): bool {
        if (
            preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $exportId) !== 1
            || ! Str::isUuid($leaseToken)
            || $leaseToken !== strtolower($leaseToken)
            || $failure === null
        ) {
            throw new InvalidArgumentException('report_export_attempt_finalization_invalid');
        }

        $errorCode = $failure instanceof ReportContractException
            ? $failure->errorCode
            : ReportErrorCode::REPORT_INTERNAL_ERROR;

        return $this->store->failLeased($exportId, $leaseToken, $errorCode, $occurredAt);
    }
}
