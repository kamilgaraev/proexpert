<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotIdentityValidator;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use Throwable;

final readonly class CanonicalReportSnapshotIdentityValidator implements ReportSnapshotIdentityValidator
{
    public function __construct(private ReportSnapshotIdentityBuilder $builder) {}

    public function assertMatches(
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
        mixed $persistedIdentity,
    ): void {
        try {
            $calculated = $this->builder->build($query, $snapshot, $result);
            if (! is_string($persistedIdentity)
                || preg_match('/^[a-f0-9]{64}$/D', $persistedIdentity) !== 1
                || ! hash_equals($persistedIdentity, $calculated->value)) {
                throw new \InvalidArgumentException('report_snapshot_identity_mismatch');
            }
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, previous: $exception);
        }
    }
}
